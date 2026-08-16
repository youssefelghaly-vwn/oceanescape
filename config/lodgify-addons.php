<?php

/**
 * config/lodgify-addons.php
 *
 * WHY THIS FILE EXISTS
 * Lodgify does not expose add-ons through any credentialed API. The dashboard
 * reads them from rates.lodgify.com/api/v2/rates/addons/property/{id}, which is
 * locked to a logged-in app.lodgify.com session — every API-key variant returns
 * 401/403. Until a public endpoint turns up, mirror the add-ons here.
 *
 * Keyed by Lodgify property id. Find ids at /debug/lodgify → cottages[].id
 *
 * FIELDS
 *   name          shown to the guest                              (required)
 *   price         amount in the property's currency               (required)
 *   charge_type   PerStay | PerNight | PerPerson | PerPersonPerNight
 *   description   optional supporting line
 *   image         optional; a public/ path or absolute URL
 *   required      true = always included, guest cannot remove it
 *   max_quantity  cap on the stepper (default 10)
 *
 * KEEP IN SYNC with Lodgify → Rentals → Pricing → Add-ons. Nothing validates
 * this against Lodgify, so a stale price here charges the wrong amount.
 */

return [

    // 836351 => [
    //     [
    //         'id'          => '155523',          // Lodgify's add-on id, if you have it
    //         'name'        => 'Early check-in',
    //         'description' => 'Arrive from 11am instead of 2pm.',
    //         'price'       => 10.00,
    //         'charge_type' => 'PerStay',
    //         'required'    => false,
    //     ],
    //     [
    //         'name'        => 'Mid-stay clean',
    //         'price'       => 45.00,
    //         'charge_type' => 'PerStay',
    //         'max_quantity'=> 2,
    //     ],
    // ],

];