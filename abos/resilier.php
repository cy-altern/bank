<?php
/**
 * Resilier un abonnement
 *
 * @plugin     bank
 * @copyright  2014
 * @author     cedric
 * @licence    GNU/GPL
 * @package    SPIP\Abos\API
 */
if (!defined('_ECRIRE_INC_VERSION')){
	return;
}

include_spip('base/abstract_sql');

/**
 * @param $id
 * @param array $options
 *   bool immediat : pour forcer la resiliation immediatement, sans attendre la prochaine echeance de paiement
 *   string message : message stocke en base pour la resiliation
 *   bool notify_bank : lancer un appel au presta bancaire pour resilier aupres de lui les paiements auto
 * @return bool
 */
function abos_resilier_dist($id, $options = array()){

	spip_log("abos/resilier id=$id", "abos_resil");

	/*
	if (strncmp($id,"uid:",4)==0){
		$where = "abonne_uid=".sql_quote(substr($id,4));
	}
	else {
		$where = "id_abonnement=".intval($id);
	}
	*/

	if (!isset($options['message'])){
		$options['message'] = '';
	}
	if (!isset($options['immediat'])){
		$options['immediat'] = false;
	}
	if (!isset($options['notify_bank'])){
		$options['notify_bank'] = true;
	}
	if (!isset($options['erreur'])){
		$options['erreur'] = false;
	}

	$args = array(
		'id' => $id,
		'message' => $options['message'],
		'notify_bank' => $options['notify_bank'],
		'erreur' => $options['erreur'],
	);
	$now = date('Y-m-d H:i:s');
	if ($options['immediat']){
		$args['statut'] = 'resilie';
		$args['date_fin'] = $now;
		$args['date_echeance'] = $now;
	} else {
		$args['date_fin'] = "date_echeance";
	}

	// appel du pipeline, a charge pour lui d'appeler la fonction
	// abos_resilier_notify_bank($abonne_uid,$mode_paiement)
	// et de mettre a jour les infos de statut/date de fin d'abonnement
	$ok = pipeline(
		'bank_abos_resilier',
		array(
			'args' => $args,
			'data' => true,
		)
	);

	return $ok;
}

/**
 * Appeler le presta bancaire si celui-ci dispose d'une methode dans son API pour resilier un abonnement
 * @param string $abonne_uid
 * @param string $mode_paiement
 * @return bool
 *   renvoie false si le presta bancaire indique un echec, true dans tous les autres cas
 */
function abos_resilier_notify_bank($abonne_uid, $mode_paiement = null){

	if (!$mode_paiement){
		$mode_paiement = sql_getfetsel("mode", "spip_transactions", "abo_uid=" . sql_quote($abonne_uid, '', 'text'), "", "id_transaction DESC");
	}
	spip_log("abos/resilier_notify_bank abonne_uid=$abonne_uid mode=$mode_paiement", "abos_resil");

	$ok = true;
	// notifier au presta bancaire si besoin
	if ($mode_paiement AND $abonne_uid){

		include_spip("inc/bank");
		if (!$config = bank_config($mode_paiement, true)
			OR !isset($config['presta'])
			OR !$presta = $config['presta']){
			spip_log("abos/resilier_notify_bank presta inconnu pour mode=$mode_paiement", "abos_resil" . _LOG_ERREUR);
		}

		if ($presta AND $presta_resilier = charger_fonction('resilier_abonnement', "presta/$presta/call", true)){
			$ok = $presta_resilier($abonne_uid);
			if (!$ok){
				spip_log("Resiliation abo " . $abonne_uid . " refuse par le prestataire", 'abos_resil' . _LOG_ERREUR);
			}
		} else {
			spip_log("abos/resilier_notify_bank : pas de methode resilier_abonnement pour le presta $presta", "abos_resil" . _LOG_INFO_IMPORTANTE);
			// declencher un traitement fallback via bank_simple_call_resilier_abonnement
			$ok = false;
		}

		if (!$ok){
			bank_simple_call_resilier_abonnement($abonne_uid, $mode_paiement);
			// TODO ajouter un message a l'abonnement pour le feedback user
			spip_log("Envoi email de desabo " . $abonne_uid . " au webmestre", 'abos_resil' . _LOG_INFO_IMPORTANTE);

			// neanmoins, si plus d'echeance prevue, on peut finir
			// (cas d'un abos deja resilie fin de mois qu'on veut forcer a resilier immediatement)
			// TODO eventuel
		}
	}

	return $ok;
}