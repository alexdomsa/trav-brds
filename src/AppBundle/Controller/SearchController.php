<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\DomCrawler\Crawler;
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
        $dayInfoList = $flightDepartCrawler->filter('.arrivalDayDisclaimer')->extract(array('_text'));
        $stopsList = $flightDepartCrawler->filter('.stopsText')->extract(array('_text'));
//        $list = $flightDepartCrawler->filter('.airContainer2');

        //format dateList
        $j = 0;
        while ($j < count($dateList)) {
            $dateList[$j] = $this->formatDepartureDate($dateList[$j]);
            $j++;
        }

        $depart = array();
        $i = 0;
        while ($i < count($priceList)) {
            $item = array();
            $item['price'] = (int) str_replace(array('$', ','), '', $priceList[$i]);
            $item['price'] = (int) $item['price'];

            $rawOriginData = trim(preg_replace('/\s\s+/', ' ', $hourList[$i*3]));
            $formattedOriginData = $this->transformAirportRawData($rawOriginData, $dateList[$i]);
            $item['origin'] = $formattedOriginData['name'];
            $item['departDateTime'] = $formattedOriginData['date'];

            // update arrival date if is next day
            $info = trim(preg_replace('/\s\s+/', '', $dayInfoList[$i]));
            if (!empty($info)) {
                $date = \DateTime::createFromFormat('l, F d, Y', $dateList[$i]);
                $date->modify('+1 day');
                $dateList[$i] = $date->format('l, F d, Y');
            }

            $rawDestinationData = trim(preg_replace('/\s\s+/', ' ', $hourList[$i*3+2]));
            $formattedDestinationData = $this->transformAirportRawData($rawDestinationData, $dateList[$i]);
            $item['destination'] = $formattedDestinationData['name'];
            $item['arriveDateTime'] = $formattedDestinationData['date'];

            $item['stops'] = (int) $stopsList[$i];

            $depart[$i] = $item;
            $i++;
        }

        // return flight
        $flightReturnCrawler = $client->request('GET', 'http://package.barcelo.com/Availability/Default.aspx?itin=1&cmpt=A&leg=2');
        $priceList = $flightReturnCrawler->filter('.airPrice2')->extract(array('_text'));
        $hourList = $flightReturnCrawler->filter('.availBold2')->extract(array('_text'));
        $dateList = $flightReturnCrawler->filter('.flightDetailsTitles')->extract(array('_text'));
        $dayInfoList = $flightReturnCrawler->filter('.arrivalDayDisclaimer')->extract(array('_text'));
        $stopsList = $flightReturnCrawler->filter('.stopsText')->extract(array('_text'));

        //format dateList
        $j = 0;
        while ($j < count($dateList)) {
            $dateList[$j] = $this->formatDepartureDate($dateList[$j]);
            $j++;
        }

        $return = array();
        $i = 0;
        while ($i < count($priceList)) {
            $item = array();
            $item['price'] = (int) str_replace(array('$', ','), '', $priceList[$i]);
            $item['price'] = (int) $item['price'];

            $rawOriginData = trim(preg_replace('/\s\s+/', ' ', $hourList[$i*3]));
            $formattedOriginData = $this->transformAirportRawData($rawOriginData, $dateList[$i]);
            $item['origin'] = $formattedOriginData['name'];
            $item['departDateTime'] = $formattedOriginData['date'];

            // update arrival date if is next day
            $info = trim(preg_replace('/\s\s+/', '', $dayInfoList[$i]));
            if (!empty($info)) {
                $date = \DateTime::createFromFormat('l, F d, Y', $dateList[$i]);
                $date->modify('+1 day');
                $dateList[$i] = $date->format('l, F d, Y');
            }

            $rawDestinationData = trim(preg_replace('/\s\s+/', ' ', $hourList[$i*3+2]));
            $formattedDestinationData = $this->transformAirportRawData($rawDestinationData, $dateList[$i]);
            $item['destination'] = $formattedDestinationData['name'];
            $item['arriveDateTime'] = $formattedDestinationData['date'];

            $item['stops'] = (int) $stopsList[$i];

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
            $leg->departTerminal = $departJorney['origin'];
            $leg->departDateTime = $departJorney['departDateTime'];
            $leg->arriveTerminal = $departJorney['destination'];
            $leg->arriveDateTime = $departJorney['arriveDateTime'];
            $leg->miles = 150;
            $leg->durationMinutes = 150;
            $leg->disembarkAtArrival = true;
            $cnt = 0;
            while ($cnt < $departJorney['stops'] + 1) {
                $leg->sequenceNum = $cnt + 1;
                $segment->leg[] = $leg;
                $cnt++;
            }

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
            $leg->departTerminal = $returnJorney['origin'];
            $leg->departDateTime = $returnJorney['departDateTime'];
            $leg->arriveTerminal = $returnJorney['destination'];
            $leg->arriveDateTime = $returnJorney['arriveDateTime'];
            $leg->miles = 150;
            $leg->durationMinutes = 150;
            $leg->disembarkAtArrival = true;
            $cnt = 0;
            while ($cnt < $returnJorney['stops'] + 1) {
                $leg->sequenceNum = $cnt + 1;
                $segment->leg[] = $leg;
                $cnt++;
            }

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
     * @Route("/hsm/v1/api/hotels/search", name="hotels")
     * @Method({"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getHotelsAction(Request $request)
    {
        // Prepare data from request for form submission
        $origin = $request->query->get('departingAirportCode');
        $destination = $request->query->get('airportCode');
        $startDate = \DateTime::createFromFormat('Y-m-d', $request->query->get('travelStartDate'));
        $startDate = $startDate->format('n/j/Y');
        $endDate = \DateTime::createFromFormat('Y-m-d', $request->query->get('travelEndDate'));
        $endDate = $endDate->format('n/j/Y');
        $rooms = $request->query->get('rooms');
        $adults = (int) $request->query->get('adults');
        $children = (int) $request->query->get('children');
        $flightPrice = (int) $request->query->get('flightPrice');

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
//                    = $requestData['travelerProfile']['childAge'][$i - 1];
                    = rand(1, 14); // hardcode children age since it is not receive from "Priceline" call
                $i++;
            }
        }

        // Access and submit form
        $client = new Client();
        $crawler = $client->request('GET', 'http://package.barcelo.com/Search/Default.aspx');
        $form = $crawler->selectButton('Search')->form();
        $client->submit($form, $formParams);

        // Select flight before accessing hotels page, so that it reflects correct price
        $flightDepartCrawler = $client->request('GET', 'http://package.barcelo.com/Availability/Default.aspx?itin=1&cmpt=A&leg=1');
        $itemIds = $flightDepartCrawler->filter('.airContainer2')->each(function (Crawler $node, $i) use ($flightPrice) {
            $price = $node->filter('.airPrice2')->text();
            $price = (int) str_replace(array('$', ','), '', $price);

            if ($price === $flightPrice) {
                $value = $node->filter('#Button3')->getNode(0)->getAttribute('onclick');
                $expl = explode(',', $value);
                $itemId = preg_replace('/\'/', '', $expl[1]);

                return $itemId;
            }

            return null;
        });

        foreach ($itemIds as $itemId) {
            if (!is_null($itemId)) {
                $jsonItem = new \stdClass();
                $jsonItem->itinerary = 1;
                $jsonItem->itemId = $itemId;
                $jsonItem->legNumber = 1;
                $jsonItem->availabilityFlow = 'StandardToModalFlow';
                $json = '{"itinerary":1,"itemId":"' . $itemId . '","legNumber":1,"availabilityFlow":"StandardToModalFlow"}';
                $client->request('POST', 'http://package.barcelo.com/availability/AvailabilityAddItem.asmx/AddAirItem', array(), array(), array('HTTP_CONTENT_TYPE' => 'application/json'), $json);

                break;
            }
        }
        $crawler = $client->request('GET', 'http://package.barcelo.com/Availability/Default.aspx?itin=1&cmpt=H');

        // Select elements from crawler
        $nameList = $crawler->filter('.hotelTitleZone2')->extract(array('_text'));
        $priceList = $crawler->filter('.componentPriceHotel2')->extract(array('_text'));
        $locationList = $crawler->filter('.hotelLocationZone')->extract(array('_text'));
        $landmarkList = $crawler->filter('.hotelLandmark')->extract(array('_text'));
        $imageFilterList = $crawler->filter('.imgPosition');
        $imageList = array();
        foreach ($imageFilterList as $element) {
            $imageList[] = $element->getAttribute('src');
        }
        unset($imageFilterList);

        $response = array();
        $i = 0;
        while ($i < count($nameList)) {
            $hotel = new \stdClass();
            $hotel->id = hash('md5', time() . rand());
            $hotel->url = null;
            $hotel->name = trim(preg_replace('/\s\s+/', '', $nameList[$i]));
            $hotel->address = '';                   // sa completam cu adresa corecta dupa ce incarcam harta sau sa punem locationList?
            $hotel->addressComponents = array();
            $hotel->phone = null;
            $hotel->latitude = '';
            $hotel->longitude = '';
            $hotel->mapurl = '';
            $hotel->imageUrl = $imageList[$i];
            $hotel->distanceFromAirport = $this->extractDistance($landmarkList[$i]);
            $hotel->description = null;
            $hotel->score = '';
            $hotel->location = $this->extractLocation($locationList[$i]);
            $hotel->rooms = array();
            $hotel->dining = null;
            $hotel->accommodations = null;
            $hotel->amenities = null;
            $hotel->featured = null;
            $hotel->slideshowimages = null;

            // extract room information
            $val = $crawler->filter('#_f' . $i)->filter('input[name="hotelComponentBtn"]')->getNode(0)->getAttribute('onclick');
            preg_match_all('/\?(.*?)\'/', $val, $out);
            $partUrl = '?' . trim($out[1][0]);
            $roomInfoCrawler = $client->request('GET', 'http://package.barcelo.com/Availability/RatesForHotel.aspx' . $partUrl);

            $roomTypeNameList = $roomInfoCrawler->filter('.roomTypeName2')->extract('_text');
            $roomPriceList = $roomInfoCrawler->filter('.roomSelect')->extract('_text');
            $j = 0;
            while ($j < count($roomTypeNameList)) {
                $room = new \stdClass();
                $room->name = trim(preg_replace('/\s\s+/', '', $roomTypeNameList[$j]));
                $room->roomRecId = hash('md5', time() . rand());
                $room->description = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Donec neque felis, vestibulum eu viverra eget, maximus quis lacus. Vestibulum euismod in erat id consequat.';
                $room->image = '';
                $room->numQualified = null;
                $room->availCodeID = 1;

                $guestCount = new \stdClass();
                $guestCount->nights = array();
                $totalPrice = $this->extractTotalPrice($roomPriceList[$j]);
                $guestCount->regularPrice = $totalPrice;
                $guestCount->totalPrice = $totalPrice;
                $guestCount->taxes = 0;
                $guestCount->numGuests = $adults + $children;

                $room->guestCount = $guestCount;

                $hotel->rooms[] = $room;

                $j++;
            }

            $response[] = $hotel;
            $i++;
        }

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

        $dateTime = $day . ' ' . $time;
        $date = \DateTime::createFromFormat('l, F d, Y g:i A', $dateTime);

        $result['date'] = $date->format('Y-m-d\TH:i:s.uP');

        return $result;
    }

    /**
     * Format data for departure date
     *
     * @param $raw
     *
     * @return string
     */
    public function formatDepartureDate($raw)
    {
        $raw = explode(': ', $raw);
        $date = trim($raw[1]);

        return $date;
    }

    /**
     * Extract distance from provided string
     *
     * @param $data
     *
     * @return float
     */
    public function extractDistance($data)
    {
        preg_match_all('/\d+(?:\.\d+)?/', $data, $matches);
        $floats = array_map('floatval', $matches[0]);

        return $floats[0];
    }

    /**
     * Extract location from provided string
     *
     * @param $data
     *
     * @return string
     */
    public function extractLocation($data)
    {
        $data = trim(preg_replace('/\s\s+/', '', $data));
        preg_match_all('/\:([A-Za-z0-9\- ]+?)\|/', $data, $out);

        return trim($out[1][0]);
    }

    /**
     * Extract total price for room from provided string
     *
     * @param $data
     *
     * @return int
     */
    public function extractTotalPrice($data)
    {
        $data = trim(preg_replace('/\s\s+/', '', $data));
        $out = explode('$', $data);

        return (int) str_replace(array(','), '', $out[2]);
    }
}

