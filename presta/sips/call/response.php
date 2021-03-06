<?php
/*
 * Paiement
 * Commande, transaction, paiement
 *
 * Auteurs :
 * Cedric Morin, Yterium.com
 * (c) 2012-2019 - Distribue sous licence GNU/GPL
 *
 */

include_spip('presta/sips/inc/sips');
include_spip('inc/date');

/**
 * Verifier le statut d'une transaction lors du retour de l'internaute
 *
 * @param array $config
 * @param null|array $response
 * @return array
 */
function presta_sips_call_response_dist($config, $response = null){

	include_spip('inc/bank');
	$mode = $config['presta'];

	include_spip('inc/config');
	$merchant_id = $config['merchant_id'];
	$service = $config['service'];
	$certif = $config['certificat'];

	// recuperer la reponse en post et la decoder
	if (is_null($response)){
		$response = sips_response($service, $merchant_id, $certif);
	}

	if ($response['merchant_id']!==$merchant_id){
		return bank_transaction_invalide(0,
			array(
				'mode' => $mode,
				'erreur' => "merchant_id invalide",
				'log' => sips_shell_args($response)
			)
		);
	}

	return sips_traite_reponse_transaction($config, $response);
}
