<?php

namespace Qubiqx\QcommerceEcommerceKeendelivery\Classes;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Qubiqx\QcommerceCore\Classes\Sites;
use Qubiqx\QcommerceCore\Models\Customsetting;
use Qubiqx\QcommerceEcommerceCore\Models\Order;
use Qubiqx\QcommerceEcommerceKeendelivery\Models\KeendeliveryShippingMethod;
use Qubiqx\QcommerceEcommerceKeendelivery\Models\KeendeliveryShippingMethodService;
use Qubiqx\QcommerceEcommerceKeendelivery\Models\KeendeliveryShippingMethodServiceOption;

class KeenDelivery
{
    public static function isConnected($siteId = null)
    {
        if (! $siteId) {
            $siteId = Sites::getActive();
        }

        $response = Http::get('https://portal.keendelivery.com/api/v2/authorization?api_token=' . Customsetting::get('keen_delivery_api_key', $siteId));
        $response = json_decode($response->body(), true);
        if (isset($response['authorized']) && $response['authorized']) {
            return true;
        } else {
            return false;
        }
    }

    public static function syncShippingMethods($siteId = null)
    {
        if (! $siteId) {
            $siteId = Sites::getActive();
        }

        if (! self::isConnected($siteId)) {
            return;
        }

        $response = Http::get('https://portal.keendelivery.com/api/v2/shipping_methods?api_token=' . Customsetting::get('keen_delivery_api_key', $siteId));
        $response = json_decode($response->body(), true);

        foreach ($response['shipping_methods'] as $keenShippingMethod) {
            $shippingMethod = KeendeliveryShippingMethod::updateOrCreate(
                [
                    'value' => $keenShippingMethod['value'],
                    'site_id' => $siteId,
                ],
                [
                    'name' => $keenShippingMethod['text'],
                ]
            );

            foreach ($keenShippingMethod['services'] as $service) {
                $shippingMethodService = KeendeliveryShippingMethodService::updateOrCreate(
                    [
                        'keendelivery_shipping_method_id' => $shippingMethod->id,
                        'value' => $service['value'],
                    ],
                    [
                        'name' => $service['text'],
                    ]
                );

                foreach ($service['options'] as $option) {
                    $shippingMethodServiceOption = KeendeliveryShippingMethodServiceOption::updateOrCreate(
                        [
                            'keendelivery_shipping_method_service_id' => $shippingMethodService->id,
                            'field' => $option['field'],
                            'name' => $option['text'],
                        ],
                        [
                            'type' => $option['type'],
                            'mandatory' => $option['mandatory'],
                            'choices' => $option['choices'],
                        ]
                    );
                }
            }
        }
    }

    public static function getActiveShippingMethods($siteId)
    {
        $shippingMethods = self::getShippingMethods($siteId);

        foreach ($shippingMethods as $shippingKey => $shippingMethod) {
            foreach ($shippingMethod['services'] as $serviceKey => $service) {
                if (! $shippingMethods[$shippingKey]['services'][$serviceKey]['enabled']) {
                    unset($shippingMethods[$shippingKey]['services'][$serviceKey]);
                }
            }
        }

        return $shippingMethods;
    }

    public static function createShipment(Order $order, $formData)
    {
        $data = [
            'product' => $formData['shipping_method'],
            'service' => $formData['service'],
            'amount' => 1,
            'reference' => 'Order ' . $order->invoice_id,
            'company_name' => $order->company_name,
            'contact_person' => $order->name,
            'street_line_1' => $order->street,
            'number_line_1' => $order->house_nr,
            'number_line_1_addition' => '',
            'zip_code' => $order->zip_code,
            'city' => $order->city,
            'country' => $order->countryIsoCode,
            'phone' => $order->phone_number,
            'email' => $order->email,
        ];

        foreach ($formData as $key => $value) {
            if (Str::contains($key, 'shipping_method_service_option_') && $value) {
                $data[str_replace('shipping_method_service_option_', '', $key)] = $value;
            }
        }

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->post('https://portal.keendelivery.com/api/v2/shipment?api_token=' . Customsetting::get('keen_delivery_api_key'), $data);
        $response = json_decode($response->body(), true);

        return $response;
    }

    public static function getLabelsFromShipments($shipmentIds)
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->post('https://portal.keendelivery.com/api/v2/label?api_token=' . Customsetting::get('keen_delivery_api_key'), [
            'shipments' => $shipmentIds,
        ]);
        $response = json_decode($response->body(), true);

        return $response;
    }
}