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


/**
 * @param array $config
 * @param int $id_transaction
 * @param string $transaction_hash
 * @param array $options
 * @return array|string
 */
function presta_sipsv2_payer_acte_dist($config, $id_transaction, $transaction_hash, $options = array()){

	$call_request = charger_fonction('request', 'presta/sipsv2/call');
	$contexte = $call_request($id_transaction, $transaction_hash, $config);
	$contexte['config'] = $config;

	$contexte['sandbox'] = ($config['mode_test'] ? ' ' : '');
	$contexte = array_merge($options, $contexte);

	return recuperer_fond('presta/sipsv2/payer/acte', $contexte);

}

