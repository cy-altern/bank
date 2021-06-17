<?php
/*
 * Paiement Bancaire
 * module de paiement bancaire multi prestataires
 * stockage des transactions
 *
 * Auteurs :
 * Cedric Morin, Nursit.com
 * (c) 2012-2019 - Distribue sous licence GNU/GPL
 *
 */
if (!defined('_ECRIRE_INC_VERSION')){
	return;
}

define('_PAYBOX_DIRECTPLUS_DEBIT', '00002');
define('_PAYBOX_DIRECTPLUS_DEBIT_ABONNE', '00052');
define('_PAYBOX_DIRECTPLUS_AUTHDEBIT_ABONNE', '00053');

include_spip('presta/paybox/inc/paybox');

/**
 * il faut avoir un id_transaction et un transaction_hash coherents
 * pour se premunir d'une tentative d'appel exterieur
 *
 * @param int $id_transaction
 * @param string $transaction_hash
 * @param string $refabonne
 * @param string $ppps
 *   fournit par Paybox lors de l'appel initial avec un ppps:U;
 * @param array $config
 *   configuration paybox qui contient les infos de connexion directplus
 * @return string
 */
function presta_paybox_call_directplus_dist($id_transaction, $transaction_hash, $refabonne, $ppps, $config = null){

	include_spip('inc/bank');
	if (!$config){
		$config = bank_config("paybox", true);
	}
	$config['mode'] .= "_dplus"; // pour les logs
	$mode = $config['mode'];

	if (!$row = sql_fetsel("*", "spip_transactions", "id_transaction=" . intval($id_transaction) . " AND transaction_hash=" . sql_quote($transaction_hash))){
		spip_log("Transaction inconnue $id_transaction/$transaction_hash", $mode . _LOG_ERREUR);
		return "";
	}

	// securite : eviter de faire payer plusieurs fois une meme transaction si bug en amont
	if ($row['statut']=='ok'){
		spip_log("Transaction $id_transaction/$transaction_hash deja reglee", $mode . _LOG_INFO_IMPORTANTE);
		return "";
	}

	if (!$row['id_auteur']
		AND isset($GLOBALS['visiteur_session']['id_auteur'])
		AND $GLOBALS['visiteur_session']['id_auteur']){
		sql_updateq("spip_transactions", array("id_auteur" => $row['id_auteur'] = $GLOBALS['visiteur_session']['id_auteur']), "id_transaction=" . intval($id_transaction));
	}

	// recuperer l'email
	$mail = sql_getfetsel('email', "spip_auteurs", 'id_auteur=' . intval($row['id_auteur']));

	// passage en centimes d'euros
	$montant = intval(round(100*$row['montant']));
	if (strlen($montant)<10){
		$montant = str_pad($montant, 10, '0', STR_PAD_LEFT);
	}

	//		Affectation des parametres obligatoires
	$parm = array('VERSION' => '00104', 'SITE' => $config['PBX_SITE'], 'RANG' => $config['PBX_RANG'], 'IDENTIFIANT' => '');

	// cas de PBX_RANG : paybox fournit 001 mais il faut envoyer 01 au serveur
	$parm['RANG'] = str_pad(intval($parm['RANG']), 2, '0', STR_PAD_LEFT);

	$parm['CLE'] = $config['DIRECT_PLUS_CLE'];
	$parm['DATEQ'] = date('dmYHis');
	$parm['TYPE'] = _PAYBOX_DIRECTPLUS_AUTHDEBIT_ABONNE;
	$parm['DEVISE'] = "978";
	$parm['REFERENCE'] = intval($id_transaction);
	$parm['ARCHIVAGE'] = intval($id_transaction);
	$parm['DIFFERE'] = '000';
	$parm['NUMAPPEL'] = '';
	$parm['NUMTRANS'] = '';
	$parm['AUTORISATION'] = '';
	$parm['MONTANT'] = $montant;

	$parm['REFABONNE'] = $refabonne;

	$ppps = explode('  ', $ppps);
	$parm['PORTEUR'] = str_pad($ppps[0], 19, ' ', STR_PAD_RIGHT); // NO CB
	$parm['DATEVAL'] = substr($ppps[1], 2) . substr($ppps[1], 0, 2); // VAL CB
	$parm['CVV'] = $ppps[2]; // CCV CB


	include_spip('inc/distant');

	// numero de question incremental
	// dans spip_meta
	// on recommence si collision par concurence...
	$maxtry = 5;
	do {
		$num_question = intval(sql_getfetsel("valeur", "spip_meta", "nom=" . sql_quote('payboxnumquestion')));
		$num_question++;
		ecrire_meta('payboxnumquestion', $num_question);

		$parm['NUMQUESTION'] = $num_question;
		#var_dump($parm);

		// requete en POST sur PAYBOX DIRECT PLUS
		$url = paybox_url_directplus($config);
		$options = array(
			'taille_max' => 1048576,
			'datas' => $parm,
		);
		$res = recuperer_url($url, $options);
		if (!$res or empty($res['page'])) {
			spip_log("paybox_call_directplus: Echec appel de recuperer_url sur $url avec ".json_encode($parm), $mode . _LOG_ERREUR);
			$r = array();
		}
		else {
			parse_str($res['page'], $r);
		}

		if (!empty($r)) {
			if ($r['CODEREPONSE']=='00005'){
				spip_log("paybox_call_directplus: Collision Reponse : ".json_encode($res), $mode . _LOG_INFO_IMPORTANTE);
				// hum
				sleep(1);
			} else {
				spip_log("paybox_call_directplus: Reponse : ".json_encode($res), $mode . _LOG_DEBUG);
			}
		}

	} while ((empty($r) or $r['CODEREPONSE']=='00005') AND $maxtry-->0);

	if (empty($r)) {
		return '';
	}
	#var_dump($r);
	/*
	 * array(10) {
  ["NUMTRANS"]=>
  string(10) "0000617104"
  ["NUMAPPEL"]=>
  string(10) "0000981593"
  ["NUMQUESTION"]=>
  string(10) "0000095720"
  ["SITE"]=>
  string(7) "1999888"
  ["RANG"]=>
  string(2) "99"
  ["AUTORISATION"]=>
  string(6) "XXXXXX"
  ["CODEREPONSE"]=>
  string(5) "00000"
  ["COMMENTAIRE"]=>
  string(27) "Demande trait?e avec succ?s"
  ["REFABONNE"]=>
  string(5) "95720"
  ["PORTEUR"]=>
  string(19) "SLDLrcsLMPC        "
	}
	*/

	// renommons en coherence avec Paybox System
	$response = array(
		'id_transaction' => $id_transaction,
		'erreur' => $r['CODEREPONSE'],
		'auth' => $r['AUTORISATION'],
		'trans' => $r['NUMTRANS'],
		'montant' => $parm['MONTANT'],
		'abo' => $r['REFABONNE'],
		'valid' => $ppps[1],
	);

	$call_response = charger_fonction('response', 'presta/paybox/call');
	return $call_response($config, $response);
}
