<?php
// EMC M&R/Watch4Net web service (APG-WS) datasource plugin
//
//    w4n:devicename:partname
//
// Hints:
//    w4n_apg_ws          APG-WS base url (e.g. http://localhost:58080/APG-WS/wsapi)
//    w4n_apg_ws_username web service username
//    w4n_apg_ws_password web service password
//

class WeatherMapDataSource_w4n extends WeatherMapDataSource {

    function Init(&$map)
    {
        if(!function_exists('is_soap_fault')) {
            wm_debug("W4N Init: PHP SOAP module not installed on this host.\n");
            return(FALSE);
        }

        $w4n_ws             = $map->get_hint("w4n_apg_ws");
        $w4n_db             = "$w4n_ws/db?wsdl";
        $w4n_username       = $map->get_hint("w4n_apg_ws_username");
        $w4n_password       = $map->get_hint("w4n_apg_ws_password");

        if ($w4n_username=='' || $w4n_password=='' || $w4n_ws=='') {
          return(FALSE);
        }
        wm_debug ("W4N Init: APG-WS URL $w4n_ws\n");

        try {
            wm_debug ("W4N Init: Instantiate SoapClient\n");
            $client = new stringSoapClient($w4n_db, array('login' => $w4n_username, 'password' => $w4n_password));
            // add soapclient object to map
            $map->add_hint("w4n_client_ref",$client);
        } catch (Exception $e) {
            wm_warn ("W4N Init: Exception when instantiating SoapClient:". $e->toString() ."\n");
            $map->add_hint("w4n_datasource_error","true");
            return(FALSE);
        }
        return(TRUE);
    }

    function Recognise($targetstring)
    {
        # w4n:device:interface
        # w4n:ams-koo-score-1:xe-10/2/0
        # w4n:ams-koo-score-1:ae0
        # w4n:ams-koo-access-1:GigabitEthernet10.0
# TODO: Allow for other part names (datasources)
        if(preg_match("/^w4n:([\-a-zA-Z0-9_]+):([\-a-zA-Z0-9_\.\/]+)$/",$targetstring,$matches))
        {
            wm_debug ("W4N Recognise: Recognised target string: $targetstring\n");
            return TRUE;
        }
        if(preg_match("/^w4n:([\-a-zA-Z0-9_]+):([\-a-zA-Z0-9_\.\/]+)$/",urldecode($targetstring),$matches)) {
            wm_debug ("W4N Recognise: Recognised url encoded target string: $targetstring\n");
            return TRUE;
        }
        return FALSE;
    }

    function ReadData($targetstring, &$map, &$item)
    {
        $ds0 = "ifInOctets";
        $ds1 = "ifOutOctets";
        $data[IN] = NULL;
        $data[OUT] = NULL;
        $data_time = 0;

        $tnow   = time();
        # The interval is chosen so at least one reading is available
        $tstart = $tnow - 800;

        $multiplier = 8;  // octet to bit multiplier

        $device = NULL;
        $part = NULL;

        if ($map->get_hint("w4n_datasource_error")=='true') {
          # bail out early if some error happen during a previous ReadData in this run
          # TODO change to debug
            wm_debug ("W4N ReadData: Bail out due to a recent ReadData exception\n");
            return( array($data[IN], $data[OUT], $data_time) );
        }

# TODO allow for other expressions to allow for different datasources
        # w4n:device:interface
# TODO: Verify under what conditions a URL encoded value could be a security issue
        $targetstring = urldecode($targetstring);
        if(preg_match("/^w4n:([\-a-zA-Z0-9_]+):([\-a-zA-Z0-9_\.\/]+)$/",$targetstring,$matches))
        {
            $device = $matches[1];
            $part   = $matches[2];
        }
# Remember targetstring has passed the Recognise phase, so it's not necessary
# to care as much for problems here (but stil need to duplicate regexes here)

        wm_debug ("W4N ReadData: device:$device, part:$part\n");

        $filter = "device=='$device' & part=='$part'";// & (name=='$ds0' | name=='$ds1')";

        wm_debug ("W4N ReadData: Recover client\n");
        $client = $map->get_hint("w4n_client_ref");
        wm_debug ("W4N ReadData: Query web service\n");

        $ret0 = NULL;
        $ret1 = NULL;
        try {
            // Query one ds at a time, as querying both at once may return them in
            // different order.
            $ret0 = objtoarray($client->getObjectData(array(
              'filter' => $filter . " & (name=='$ds0')",
              'start-timestamp' => $tstart,
              'end-timestamp' => $tnow,
              'period' => 0,
              'limit' => 100
              )));
            $ret1 = objtoarray($client->getObjectData(array(
              'filter' => $filter . " & (name=='$ds1')",
              'start-timestamp' => $tstart,
              'end-timestamp' => $tnow,
              'period' => 0,
              'limit' => 100
              )));
        } catch (Exception $e) {
            wm_warn ("W4N ReadData: Exception while querying web service: ". $e->getMessage() ."\n");
            # Avoid more calls to the web service on this run.
            $map->add_hint("w4n_datasource_error","true");
        }

        if ($ret0===NULL || $ret1===NULL) {
        } else {

#       If no results are returned for a specific datasource (unlikely), there's still 
#       a placeholder where 'length' value is zero.
            $ds0Array = $ret0['timeseries']['timeserie']; //['0']; // IN
            $ds0len   = $ds0Array['length'];
            $ds1Array = $ret1['timeseries']['timeserie']; //['0']; // OUT
            $ds1len   = $ds1Array['length'];

            # retrieve last values for inOctets, outOctets
            # If several timeseries returned, go for last one
            if($ds0len>0) {
                $ts0 = NULL;
                if($ds0len==1) {
                    // When there's a single reading, it's not stored in a sub-array :(
                    $ts0 = $ds0Array['tv'];
                } else {
                    // pick last entry
                    $ts0 = $ds0Array['tv'][$ds0len-1];
                }

                $data[IN] = $ts0['v'];
                // Timestamp of data (assumed same for both datasources, 
                // and ReadData does not allow to return two different timestamps anyway)
                $data_time = $ts0['t'];
            }
            if($ds1len>0) {
                $ts1 = NULL;
                if($ds1len==1) {
                    // When there's a single reading, it's not stored in a sub-array :(
                    $ts1 = $ds1Array['tv'];
                } else {
                    $ts1 = $ds1Array['tv'][$ds1len-1];
                }
                $data[OUT] = $ts1['v'];
            }

            # Octets to bits
            $data[IN] = $data[IN] * $multiplier;
            $data[OUT] = $data[OUT] * $multiplier;
        }

        wm_debug ("W4N ReadData: Returning (".($data[IN]===NULL?'NULL':$data[IN]).",".($data[OUT]===NULL?'NULL':$data[OUT]).",$data_time)\n");
        return( array($data[IN], $data[OUT], $data_time) );
    }
}

// Wrapper to PHP's SoapClient that provides an implicit conversion to string
class stringSoapClient extends SoapClient {
  public function __toString() { 
    $objHash = "";
    if (function_exists('spl_object_hash')) {
      $objHash = spl_object_hash($this);
    }
    return "SoapClient:[$objHash]"; 
  }
}

// Simple helper function to convert soap object to array
// Blatantly stolen from APG-WS tutorial source code
function objtoarray_rec($object, &$restructarray){
    if(is_object($object)) {
        foreach($object as $key=>$value) {
            objtoarray_rec($value, $restructarray[$key]);
        }
    } else if(is_array($object)) {
        foreach($object as $key=>$value) {
            objtoarray_rec($value, $restructarray[$key]);
        }
    } else {
        $restructarray=$object;
    }
}

function objtoarray($object) {
    $ret = array();
    objtoarray_rec($object, $ret);
    return $ret;
}

// vim:ts=4:sw=4:
?>
