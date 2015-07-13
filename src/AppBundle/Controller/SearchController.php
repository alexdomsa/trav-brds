<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Goutte\Client;

class SearchController extends Controller
{
    /**
     * @Route("/resweb/rest/flight/getFlightAvail", name="getFlightAvail")
     * @Method({"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getFlightsAction(Request $request)
    {
        // Prepare data from request for form submission
        $requestRaw = $request->getContent();
        $requestData = json_decode($requestRaw, true);

        // determine repart/arrive leg keys
        if ($requestData['departArriveRequest'][0]['rph'] === '1') {
            $firstLeg = 0;
            $secondLeg = 1;
        } else {
            $firstLeg = 1;
            $secondLeg = 0;
        }

        $origin = $requestData['departArriveRequest'][$firstLeg]['departAirport'];
        $startDate = \DateTime::createFromFormat('Y-m-d', $requestData['departArriveRequest'][$firstLeg]['requestDate']);
        $startDate = $startDate->format('n/j/Y');
        $destination = $requestData['departArriveRequest'][$firstLeg]['arriveAirport'];
        $endDate = \DateTime::createFromFormat('Y-m-d', $requestData['departArriveRequest'][$secondLeg]['requestDate']);
        $endDate = $endDate->format('n/j/Y');
        $children = count($requestData['travelerProfile']['childAge']);
        $adults = $requestData['travelerProfile']['numTravelers'] - $children;

        $formParams = array(
            'ctl00$ctl01$ContentPlaceHolder$ContentPlaceHolder$SearchComponents$scc$rt$origin'                          => $origin,
            'ctl00$ctl01$ContentPlaceHolder$ContentPlaceHolder$SearchComponents$scc$rt$destination'                     => $destination,
            'ctl00$ctl01$ContentPlaceHolder$ContentPlaceHolder$SearchComponents$scc$rt$departure'                       => $startDate,
            'ctl00$ctl01$ContentPlaceHolder$ContentPlaceHolder$SearchComponents$scc$rt$return'                          => $endDate,
            'ctl00$ctl01$ContentPlaceHolder$ContentPlaceHolder$SearchComponents$scc$rt$passengers$numrooms'             => 1, // $requestData['rooms'],
            'ctl00$ctl01$ContentPlaceHolder$ContentPlaceHolder$SearchComponents$scc$rt$passengers$pr$ctl00$pi$adults'   => $adults,
            'ctl00$ctl01$ContentPlaceHolder$ContentPlaceHolder$SearchComponents$scc$rt$passengers$pr$ctl00$pi$children' => $children,
            'ctl00$ctl01$ContentPlaceHolder$ContentPlaceHolder$SearchComponents$scc$rt$hotelcheckin'                    => $startDate,
            'ctl00$ctl01$ContentPlaceHolder$ContentPlaceHolder$SearchComponents$scc$rt$hotelcheckout'                   => $endDate
        );

        if ($children > 0) {
            $i = 1;
            while ($i <= $children) {
                $formParams['ctl00$ctl01$ContentPlaceHolder$ContentPlaceHolder$SearchComponents$scc$rt$passengers$pr$ctl00$pi$cr$ctl0' . $i . '$ChildAgeInput']
                    = $requestData['travelerProfile']['childAge'][$i - 1];
                $i++;
            }
        }

        // Access and submit form
        $client = new Client();
        $crawler = $client->request('GET', 'http://package.barcelo.com/Search/Default.aspx');
        $form = $crawler->selectButton('Search')->form();
        $crawler = $client->submit($form, $formParams);

//        $link = $crawler->selectLink('Flights')->link();
//        $uri = $link->getUri();

        // depart flight
        $flightDepartCrawler = $client->request('GET', 'http://package.barcelo.com/Availability/Default.aspx?itin=1&cmpt=A&leg=1');
        $priceList = $flightDepartCrawler->filter('.airPrice2')->extract(array('_text'));
        $hourList = $flightDepartCrawler->filter('.availBold2')->extract(array('_text'));
        $dateList = $flightDepartCrawler->filter('.flightDetailsTitles')->extract(array('_text'));
//        $list = $flightDepartCrawler->filter('.airContainer2');

        $depart = array();
        $i = 0;
        while ($i < count($priceList)) {
            $item = array();
            $item['price'] = (int) str_replace(array('$', ','), '', $priceList[$i]);

            $rawOriginData = trim(preg_replace('/\s\s+/', ' ', $hourList[$i*3]));
            $formattedOriginData = $this->transformAirportRawData($rawOriginData, $dateList[$i]);
            $item['origin'] = $formattedOriginData['name'];
            $item['departDateTime'] = $formattedOriginData['date'];

            $rawDestinationData = trim(preg_replace('/\s\s+/', ' ', $hourList[$i*3+2]));
            $formattedDestinationData = $this->transformAirportRawData($rawDestinationData, $dateList[$i]);
            $item['destination'] = $formattedDestinationData['name'];
            $item['arriveDateTime'] = $formattedDestinationData['date'];

            $depart[$i] = $item;
            $i++;
        }

        // return flight
        $flightReturnCrawler = $client->request('GET', 'http://package.barcelo.com/Availability/Default.aspx?itin=1&cmpt=A&leg=2');
        $priceList = $flightReturnCrawler->filter('.airPrice2')->extract(array('_text'));
        $hourList = $flightReturnCrawler->filter('.availBold2')->extract(array('_text'));
        $dateList = $flightReturnCrawler->filter('.flightDetailsTitles')->extract(array('_text'));

        $return = array();
        $i = 0;
        while ($i < count($priceList)) {
            $item = array();
            $item['price'] = (int) str_replace(array('$', ','), '', $priceList[$i]);

            $rawOriginData = trim(preg_replace('/\s\s+/', ' ', $hourList[$i*3]));
            $formattedOriginData = $this->transformAirportRawData($rawOriginData, $dateList[$i]);
            $item['origin'] = $formattedOriginData['name'];
            $item['departDateTime'] = $formattedOriginData['date'];

            $rawDestinationData = trim(preg_replace('/\s\s+/', ' ', $hourList[$i*3+2]));
            $formattedDestinationData = $this->transformAirportRawData($rawDestinationData, $dateList[$i]);
            $item['destination'] = $formattedDestinationData['name'];
            $item['arriveDateTime'] = $formattedDestinationData['date'];

            $return[$i] = $item;
            $i++;
        }

        // construct response structure
        $response = new \stdClass();

        $payloadAttributes = new \stdClass();
        $payloadAttributes->property = array();
        $payloadAttributes->bookingTypeID = 1;
        $payloadAttributes->bookingChannelID = 1;
        $payloadAttributes->transactionIdentifier = hash('md5', time() . rand());
        $payloadAttributes->version = 1;
        $payloadAttributes->timeStamp = date('Y-m-d\TH:i:s.000P');

        $departLeg = new \stdClass();
        foreach ($depart as $departJorney) {
            $journey = new \stdClass();
            $journey->segment = array();
            $journey->priceComponent = array();
            $journey->priceComponentOptional = array(); // de completat
            $journey->flightTraveler = array();
            $journey->checkInWindow = null;
            $journey->sequenceNum = 1;
            $journey->unflownSegmentCount = 1;
            $journey->vendorName = '20';
            $journey->orderId = null;
            $journey->journeyId = null;
            $journey->checkInStatus = null;

            $segment = new \stdClass();
            $segment->leg = array();
            $segment->priceComponent = array();
            $segment->priceComponentOptional = array();
            $segment->classPriceAndAvail = array();
            $segment->priceOverrideAccepted = null;
            $segment->flightNbr = (string) rand(1, 10000);
            $segment->airlineCode = 'G4';
            $segment->operatorCode = 'G4';
            $segment->sequenceNum = 1;
            $segment->tripCode = null;
            $segment->reaccom = false;
            $journey->segment[] = $segment;

            $leg = new \stdClass();
            $leg->equipment = new \stdClass();
            $leg->departAirport = new \stdClass();
            $leg->arriveAirport = new \stdClass();
            $leg->priceComponent = array();
            $leg->priceComponentOptional = array();
            $leg->flightSchedule = null;
            $leg->sequenceNum = 1;
            $leg->departTerminal = $departJorney['origin'];
            $leg->departDateTime = $departJorney['departDateTime'];
            $leg->arriveTerminal = $departJorney['destination'];
            $leg->arriveDateTime = $departJorney['arriveDateTime'];
            $leg->miles = 150;
            $leg->durationMinutes = 150;
            $leg->disembarkAtArrival = true;
            $segment->leg[] = $leg;

            $equipment = new \stdClass();
            $equipment->seatMap = null;
            $equipment->make = 'ALL';
            $equipment->model = '31B';
            $equipment->config = null;
            $equipment->tailNbr = 'ALL';
            $leg->equipment = $equipment;

            $departAirport = new \stdClass();
            $departAirport->id = rand(1, 1000);
            $departAirport->code = $departJorney['origin'];
            $departAirport->name = $departJorney['origin'];
            $departAirport->city = $departJorney['origin'];
            $departAirport->state = 'US';
            $departAirport->allowsEBoardingPass = true;
            $leg->departAirport = $departAirport;

            $arriveAirport = new \stdClass();
            $arriveAirport->id = rand(1, 1000);
            $arriveAirport->code = $departJorney['destination'];
            $arriveAirport->name = $departJorney['destination'];
            $arriveAirport->city = $departJorney['destination'];
            $arriveAirport->state = 'US';
            $arriveAirport->allowsEBoardingPass = true;
            $leg->arriveAirport = $arriveAirport;

            $classpriceAndAvail = new \stdClass();
            $classpriceAndAvail->priceComponent = array();
            $classpriceAndAvail->classOfService = 'V';
            $classpriceAndAvail->currentAvail = 10;
            $segment->classPriceAndAvail[] = $classpriceAndAvail;

            $priceComponent1 = new \stdClass();
            $priceComponent1->priceComponent = array();
            $priceComponent1->tag = array();
            $priceComponent1->property = array();
            $priceComponent1->code = 'TOTAL';
            $priceComponent1->source = 'G4';
            $priceComponent1->description = 'TOTAL FARE';
            $priceComponent1->value = $departJorney['price'];
            $priceComponent1->date = null;
            $classpriceAndAvail->priceComponent[] = $priceComponent1;

            $priceComponent2 = new \stdClass();
            $priceComponent2->priceComponent = array();
            $priceComponent2->tag = array();
            $priceComponent2->property = array();
            $priceComponent2->code = 'BKA';
            $priceComponent2->source = 'G4';
            $priceComponent2->description = 'TOTAL FARE';
            $priceComponent2->value = $departJorney['price'];
            $priceComponent2->date = null;
            $priceComponent1->priceComponent[] = $priceComponent2;

            $priceComponent3 = new \stdClass();
            $priceComponent3->priceComponent = array();
            $priceComponent3->tag = array();
            $priceComponent3->property = array();
            $priceComponent3->code = 'BASE FARE';
            $priceComponent3->source = 'G4';
            $priceComponent3->description = 'VNEW';
            $priceComponent3->value = $departJorney['price'];
            $priceComponent3->date = null;
            $priceComponent2->priceComponent[] = $priceComponent3;

            $departLeg->journey[] = $journey;
        }
        $departLeg->priceComponent = array();
        $departLeg->priceComponentOptional = array();
        $departLeg->rph = '1';
        $departLeg->requestRPH = '1';

        $returnLeg = new \stdClass();
        foreach ($return as $returnJorney) {
            $journey = new \stdClass();
            $journey->segment = array();
            $journey->priceComponent = array();
            $journey->priceComponentOptional = array(); // de completat
            $journey->flightTraveler = array();
            $journey->checkInWindow = null;
            $journey->sequenceNum = 1;
            $journey->unflownSegmentCount = 1;
            $journey->vendorName = '20';
            $journey->orderId = null;
            $journey->journeyId = null;
            $journey->checkInStatus = null;

            $segment = new \stdClass();
            $segment->leg = array();
            $segment->priceComponent = array();
            $segment->priceComponentOptional = array();
            $segment->classPriceAndAvail = array();
            $segment->priceOverrideAccepted = null;
            $segment->flightNbr = (string) rand(1, 10000);
            $segment->airlineCode = 'G4';
            $segment->operatorCode = 'G4';
            $segment->sequenceNum = 1;
            $segment->tripCode = null;
            $segment->reaccom = false;
            $journey->segment[] = $segment;

            $leg = new \stdClass();
            $leg->equipment = new \stdClass();
            $leg->departAirport = new \stdClass();
            $leg->arriveAirport = new \stdClass();
            $leg->priceComponent = array();
            $leg->priceComponentOptional = array();
            $leg->flightSchedule = null;
            $leg->sequenceNum = 1;
            $leg->departTerminal = $returnJorney['origin'];
            $leg->departDateTime = $returnJorney['departDateTime'];
            $leg->arriveTerminal = $returnJorney['destination'];
            $leg->arriveDateTime = $returnJorney['arriveDateTime'];
            $leg->miles = 150;
            $leg->durationMinutes = 150;
            $leg->disembarkAtArrival = true;
            $segment->leg[] = $leg;

            $equipment = new \stdClass();
            $equipment->seatMap = null;
            $equipment->make = 'ALL';
            $equipment->model = '31B';
            $equipment->config = null;
            $equipment->tailNbr = 'ALL';
            $leg->equipment = $equipment;

            $departAirport = new \stdClass();
            $departAirport->id = rand(1, 1000);
            $departAirport->code = $returnJorney['origin'];
            $departAirport->name = $returnJorney['origin'];
            $departAirport->city = $returnJorney['origin'];
            $departAirport->state = 'US';
            $departAirport->allowsEBoardingPass = true;
            $leg->departAirport = $departAirport;

            $arriveAirport = new \stdClass();
            $arriveAirport->id = rand(1, 1000);
            $arriveAirport->code = $returnJorney['destination'];
            $arriveAirport->name = $returnJorney['destination'];
            $arriveAirport->city = $returnJorney['destination'];
            $arriveAirport->state = 'US';
            $arriveAirport->allowsEBoardingPass = true;
            $leg->arriveAirport = $arriveAirport;

            $classpriceAndAvail = new \stdClass();
            $classpriceAndAvail->priceComponent = array();
            $classpriceAndAvail->classOfService = 'V';
            $classpriceAndAvail->currentAvail = 10;
            $segment->classPriceAndAvail[] = $classpriceAndAvail;

            $priceComponent1 = new \stdClass();
            $priceComponent1->priceComponent = array();
            $priceComponent1->tag = array();
            $priceComponent1->property = array();
            $priceComponent1->code = 'TOTAL';
            $priceComponent1->source = 'G4';
            $priceComponent1->description = 'TOTAL FARE';
            $priceComponent1->value = $returnJorney['price'];
            $priceComponent1->date = null;
            $classpriceAndAvail->priceComponent[] = $priceComponent1;

            $priceComponent2 = new \stdClass();
            $priceComponent2->priceComponent = array();
            $priceComponent2->tag = array();
            $priceComponent2->property = array();
            $priceComponent2->code = 'BKA';
            $priceComponent2->source = 'G4';
            $priceComponent2->description = 'TOTAL FARE';
            $priceComponent2->value = $returnJorney['price'];
            $priceComponent2->date = null;
            $priceComponent1->priceComponent[] = $priceComponent2;

            $priceComponent3 = new \stdClass();
            $priceComponent3->priceComponent = array();
            $priceComponent3->tag = array();
            $priceComponent3->property = array();
            $priceComponent3->code = 'BASE FARE';
            $priceComponent3->source = 'G4';
            $priceComponent3->description = 'VNEW';
            $priceComponent3->value = $returnJorney['price'];
            $priceComponent3->date = null;
            $priceComponent2->priceComponent[] = $priceComponent3;

            $returnLeg->journey[] = $journey;
        }
        $returnLeg->priceComponent = array();
        $returnLeg->priceComponentOptional = array();
        $returnLeg->rph = '2';
        $returnLeg->requestRPH = '2';

        $response->payloadAttributes = $payloadAttributes;
        $response->error = array();
        $response->warning = array();
        $response->journeySet = array($departLeg, $returnLeg);

        return new JsonResponse($response);
    }

    /**
     * @Route("/resweb/rest/cart/getCartItems", name="getCartItems")
     * @Method({"POST"})
     *
     * @return JsonResponse
     */
    public function getCartItemsAction()
    {
        $payloadAttributes = new \stdClass();
        $payloadAttributes->property = array();
        $payloadAttributes->bookingTypeID = 1;
        $payloadAttributes->bookingChannelID = 1;
        $payloadAttributes->transactionIdentifier = hash('md5', time() . rand());
        $payloadAttributes->version = 1;
        $payloadAttributes->timeStamp = date('Y-m-d\TH:i:s.000P');

        $cartItem = new \stdClass();
        $cartItem->priceComponent = array();
        $cartItem->tag = array();
        $cartItem->property = array();
        $cartItem->code = 'CCV';
        $cartItem->source = 'G4';
        $cartItem->description = 'CARRIER USAGE CHARGE';
        $cartItem->value = 0;
        $cartItem->date = null;

        $cartItemOptional = new \stdClass();
        $cartItemOptional->priceComponent = array();
        $cartItemOptional->tag = array();
        $cartItemOptional->property = array();
        $cartItemOptional->code = 'CH';
        $cartItemOptional->source = 'G4';
        $cartItemOptional->description = 'CHG FEE';
        $cartItemOptional->value = 0;
        $cartItemOptional->date = null;

        $response = new \stdClass();
        $response->payloadAttributes = $payloadAttributes;
        $response->error = array();
        $response->warning = array();
        $response->cartItem = array($cartItem);
        $response->cartItemOptional = array($cartItemOptional);

        return new JsonResponse($response);
    }

    /**
     * Extract airport name and departure time from raw data
     *
     * @param $rawData
     * @param $day
     *
     * @return array
     */
    public function transformAirportRawData($rawData, $day)
    {
        $result = array();

        if (strpos($rawData, ' AM ') !== false) {
            $explodedData = explode('AM', $rawData);
            $time = $explodedData[0] . 'AM';
            $result['name'] = trim($explodedData[1]);
        }

        if (strpos($rawData, ' PM ') !== false) {
            $explodedData = explode('PM', $rawData);
            $time = $explodedData[0] . 'PM';
            $result['name'] = trim($explodedData[1]);
        }

        $day = explode(': ', $day);
        $dateTime = trim($day[1]) . ' ' . $time;
        $date = \DateTime::createFromFormat('l, F d, Y g:i A', $dateTime);

        $result['date'] = $date->format('Y-m-d\TH:i:s.uP');

        return $result;
    }
}

