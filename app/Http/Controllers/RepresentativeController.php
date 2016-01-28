<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use GuzzleHttp\Promise;
use App\Location;
use App\Representative;
use App\Providers\IPInfo\IPInfo;
use GoogleAPI;
use CongressAPI;
use StateAPI;

class RepresentativeController extends Controller
{

    /**
     * Home Page View
     * @param  Request $request Http Request
     * @return view
     */
    public function index(Request $request)
    {
        $ip = $request->ip();

        if (
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && stripos($request->header('User-Agent'), 'mobi') === false
        ){
            return view('pages.home', ['location' => IPInfo::getLocation($ip)]);
        }

        return view('pages.home');
    }

    /**
     * Any query page view (/zip, /state, etc.)
     * @return view
     */
    public function view()
    {
        return view('pages.home');
    }

    /**
     * Query by district
     * @param  string $state    2 digit state abbrev.
     * @param  number $district district number
     * @return json
     */
    public function district($state, $district)
    {
        $googReq = GoogleAPI::district($state, $district);
        $congReq = CongressAPI::district($state, $district);
        $stateReq = StateAPI::district($state, $district);
        $results = Promise\unwrap([$googReq, $congReq, $stateReq]);

        if (!$this->isValid($results))
            return $this->error($results);

        return $this->success($results);
    }

    /**
     * Query by zipcode
     * @param  string $zipcode 5 digit zipcode
     * @return json
     */
    public function zipcode($zipcode)
    {
        $resp = new \stdClass();
        $resp->reps = [];

        $l = Location::where('zip', intval($zipcode))->first();
        if (is_null($l)){
            return [
                'status' => 'error',
                'message' => 'zipcode not found'
            ];
        }
        $resp->reps = Representative::location($l);
        $resp->location = $l;

        return response()->json($resp);
    }

    /**
     * Query by gps
     * @param  float $lat latitude
     * @param  float $lng longitude
     * @return json
     */
    public function gps($lat, $lng)
    {
        $googReq = GoogleAPI::address($lat.','.$lng);
        $stateReq = StateAPI::gps($lat, $lng);
        $resp = new \StdClass();
        $resp->reps = [];
        $results = Promise\unwrap([$googReq, $stateReq]);

        if (!$this->isValid($results))
            return $this->error($results);

        if (isset($results[0]->reps)){
            // Representative::sync($results[0]->reps);
        }
        if (isset($results[1]->reps)){
            Representative::sync($results[1]->reps);
        }

        foreach($results[0]->divisions as $division){
            $resp->reps = array_merge(Representative::where('division',$division)->get()->all(), $resp->reps);
        }

        foreach($results[1]->divisions as $district){
            $resp->reps = array_merge(Representative::where('division', $district)->get()->all(), $resp->reps);
        }

        Representative::sortByRank($resp->reps);

        return response()->json($resp);
    }

    /**
     * Query by address
     * @param  string $address any google-able address (street + zip, state, zip, etc.)
     * @return json
     */
    public function address($address)
    {
        $geo = GoogleAPI::geocode($address);
        $gps = $geo->results[0]->geometry->location;

        if ($gps->lat && $gps->lng){
            return $this->gps($gps->lat, $gps->lng);
        }

        //if its a street address, gps will be valid, otherwise we can only get state reps
        foreach($geo->results[0]->types as $type){

            if ($type == 'street_address'){
                return $this->gps($gps->lat, $gps->lng);
            }

            if ($type == 'administrative_area_level_1'){
                $googReq = GoogleAPI::address($address);
                $congReq = CongressAPI::gps($gps->lat, $gps->lng);
                $results = Promise\unwrap([$googReq, $congReq]);

                if (!$this->isValid($results))
                    return $this->error($results);

                $c = count($results[1]);
                for ($i = 0; $i < $c; $i++){
                    $rep = $results[1][$i];
                    if (!$rep->isStateLevel()){
                        unset($results[1][$i]);
                    }
                }
                $results[1] = array_values($results[1]);
                return $this->success($results);
            }
        }
    }

    /**
     * check if api response has an error
     * @param  array  $results api response
     * @return boolean
     */
    public function isValid($results)
    {
        if (isset($results[0]->status) && $results[0]->status == 'error')
            return false;
        if (isset($results[0]->error))
            return false;
        return true;
    }

    /**
     * Convert api results to json
     * @param  array $data api results
     * @return json
     */
    public function success($data)
    {
        $response = (object) $data[0];

        if (!empty($data[1])){
            $congress = $data[1];
            //search Congress API response for reps
            foreach($response->reps as &$rep){
                $congressIndex = $rep->isIn($congress);
                if ($congressIndex !== false){
                    //load congress api data into google data
                    $rep->load($congress[$congressIndex]);
                    unset($congress[$congressIndex]);
                }
                $congress = array_values($congress);
            }

            //merge any congress reps that google didn't have
            foreach($congress as $cdata){
                $response->reps[] = $cdata;
            }
        }

        //states api has unique data, so just copy to the end
        if (!empty($data[2])){
            $response->reps = array_merge($response->reps, $data[2]);
        }

        //sort by rank
        usort($response->reps, function($a, $b){
            $ia = array_search($a->office, Representative::ranks);
            $ib = array_search($b->office, Representative::ranks);

            if ($ia === false) $ia = 6;
            if ($ib === false) $ib = 6;

            return $ia > $ib;
        });

        //load local data
        $response->reps = array_map(function($rep){

            $filename = $this->getPhotoPath($rep);
            if (\File::exists(public_path().$filename))
                $rep->photo = $filename;

            $db = Representative::where('name', $rep->name)->where('division_id', $rep->division_id)->get();
            if (count($db) == 1)
                $rep->load($db->first()->toArray());

            return $rep;

        }, $response->reps);

        return response()->json($response);
    }

    /**
     * give json error
     * @param  array $results api response data
     * @return json
     */
    public function error($results)
    {
        if (isset($results[0]->status) && $results[0]->status == 'error'){
            return response()->json($results[0]);
        }
        if (isset($results[0]->error) && gettype($results[0]->error) == 'string'){
            return response()->json(
                (object) [
                    'status' => 'error',
                    'message' => $results[0]->error
                ]
            );
        }
        return response()->json(['status' => 'error']);
    }

    /**
     * get relative url for local rep photos
     * @param  Representative $rep
     * @return string      relative url
     */
    public function getPhotoPath($rep)
    {
        $dir = '/images/reps/';
        $ext = '.jpg';

        if (isset($rep->nickname) && isset($rep->last_name)){
            return $dir.$rep->last_name.'-'.$rep->nickname.$ext;
        }else if (isset($rep->first_name) && isset($rep->last_name)){
            return $dir.$rep->last_name.'-'.$rep->first_name.$ext;
        }else if (isset($rep->name) && stripos($rep->name, " ")){
            $names = explode(" ", $rep->name);
            $first = $names[0];
            $last = $names[count($names) - 1];
            if (count($names) > 2 && (stripos($last, 'jr') !== false || stripos($last, 'sr') !== false))
                $last = $names[count($names) - 2];
            return $dir.$last.'-'.$first.$ext;
        }
        return $dir.'fail.jpg';
    }

}
