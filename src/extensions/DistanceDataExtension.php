<?php

namespace Dynamic\Locator;

use Dynamic\SilverStripeGeocoder\GoogleGeocoder;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\DataQuery;
use SilverStripe\Control\Controller;

class DistanceDataExtension extends DataExtension
{
    /**
     * @param SQLSelect $query
     * @param DataQuery|null $dataQuery
     */
    public function augmentSQL(SQLSelect $query, DataQuery $dataQuery = null)
    {
        $variables = $this->getRequestVariables(Controller::curr()->getRequest());
        $address = $variables['address'];
        $unit = $variables['unit'];

        if ($this->owner->hasMethod('updateAddressValue')) {
            $address = $this->owner->updateAddressValue($address);
        }
        if (class_exists(GoogleGeocoder::class)) {
            if ($address) { // on frontend
                $geocoder = new GoogleGeocoder($address);
                $response = $geocoder->getResult();
                $Lat = $response->getLatitude();
                $Lng = $response->getLongitude();

                // defaults to miles
                $unitVal = 3959;
                if ($unit === 'km') {
                    $unitVal = 6371;
                }

                $query
                    ->addSelect(array(
                        '( ' . $unitVal . ' * acos( cos( radians(' . $Lat . ') ) * cos( radians( `Lat` ) ) * cos( radians( `Lng` ) - radians(' . $Lng . ') ) + sin( radians(' . $Lat . ') ) * sin( radians( `Lat` ) ) ) ) AS distance',
                    ));
            } else {
                $query->addSelect('(0) AS distance');
            }
        } else {
            $query->addSelect('(0) AS distance');
        }
    }

    /**
     * From https://github.com/silverstripe/silverstripe-graphql/blob/e39e59483310923cbf14f5f4baa44033c14a270c/src/Controller.php#L60-L70
     *
     * @param HTTPRequest $request
     * @return array|mixed|null
     */
    public function getRequestVariables(HTTPRequest $request)
    {
        $contentType = $request->getHeader('Content-Type') ?: $request->getHeader('content-type');
        $isJson = preg_match('#^application/json\b#', $contentType);
        if ($isJson) {
            $rawBody = $request->getBody();
            $data = json_decode($rawBody ?: '', true);
            $variables = isset($data['variables']) ? (array)$data['variables'] : null;
        } else {
            $variables = json_decode($request->requestVar('variables'), true);
        }
        return $variables;
    }

    /**
     * Allows distance to be referenced from graphql
     *
     * @return mixed
     */
    public function getDistance()
    {
        return $this->owner->getField('distance');
    }
}
