<?php
// EMC M&R/Watch4Net web service (APG-WS) datasource plugin
//
//    w4n:device:part
//    w4n:device:part:single_ds
//    w4n:device:part:ds_A:ds_B
//
//  Examples:
//    w4n:junos_router:xe-10%2F0%2F0 (implicit: ifInOctets:ifOutOctets and convert octets to bits)
//    w4n:cisco_router:5:RoundTripTime (SLA #5 RTT; do not convert; result copied to both outputs)
//    w4n:router:GigabitEthernet0%2F0:RoundTripTime:PacketLoss (do not convert)
//
//  Hints:
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
        $_targetstring = urldecode($targetstring);
        if(preg_match("/^w4n:([\-\w]+):([\-\w\.\/]+)[:]?(\w*)[:]?(\w*)$/i",$_targetstring,$matches))
        {
            wm_debug ("W4N Recognise: Recognised target string: $_targetstring\n");
            return TRUE;
        }
        return FALSE;
    }

    // Read data from targetstring
    // Returns a 3-part array (invalue, outvalue and datavalid time_t)
    // invalue and outvalue should be -1,-1 if there is no valid data.
    // Data_time is intended to allow more informed graphing in the future
    function ReadData($targetstring, &$map, &$item)
    {
        $dsnames[IN] = "ifInOctets";  # defaults
        $dsnames[OUT] = "ifOutOctets";
        $multiplier = 8;  // octet to bit multiplier

        $data[IN] = -1;
        $data[OUT] = -1;
        $data_time = 0;
        $device = NULL;
        $part = NULL;

        $tnow   = time();
        # The interval is chosen so at least one reading is available
        $tstart = $tnow - 800;

        if ($map->get_hint("w4n_datasource_error")=='true') {
            # bail out early if some error happen during a previous ReadData in this run
            wm_debug ("W4N ReadData: Bail out due to a recent ReadData exception\n");
            return( array($data[IN], $data[OUT], $data_time) );
        }

# TODO: Verify under what conditions a URL encoded value could be a security issue
        $targetstring = urldecode($targetstring);
        preg_match("/^w4n:([\-\w]+):([\-\w\.\/]+)[:]?(\w*)[:]?(\w*)$/i",$targetstring,$matches);

        # Two first subparse items are required
        $device = $matches[1];
        $part   = $matches[2];

        # Third and fourth are optional
        if ($matches[3] != "" ) {
            $dsnames[IN] = $matches[3];
            $dsnames[OUT] = $matches[4]; # might be empty string

            # If any datasources specified, override defaults.
# TODO: Multiplier switch? On a per-datasource basis?
            $multiplier = 1;

            wm_debug("Special DS names seen (".$dsnames[IN]." and ".$dsnames[OUT].").\n");
        }

        wm_debug ("W4N ReadData: device:$device, part:$part, in:".$dsnames[IN].", out:".$dsnames[OUT]."\n");

        $filter = "device=='$device' & part=='$part'";

        $client = $map->get_hint("w4n_client_ref");

        list($data[IN], $data_time) = WeatherMapDataSource_w4n::doQuery($client, $filter, $dsnames[IN], $tstart, $tnow, $map);
        $data[IN] = $data[IN] * $multiplier;

        if ($dsnames[OUT] == "" || $dsnames[IN] == $dsnames[OUT]) {
            # If both DS are the same, do not query again.
            # If a single DS was provided, $data[OUT] is filled as well.
            $data[OUT] = $data[IN];
        } else {
            list($data[OUT], $data_time) = WeatherMapDataSource_w4n::doQuery($client, $filter, $dsnames[OUT], $tstart, $tnow, $map);
# TODO: Multiplier switch on a per-datasource basis?
            $data[OUT] = $data[OUT] * $multiplier;
        }

        wm_debug ("W4N ReadData: Returning (".($data[IN]===NULL?'NULL':$data[IN]).",".($data[OUT]===NULL?'NULL':$data[OUT]).",$data_time)\n");
        return( array($data[IN], $data[OUT], $data_time) );
    }

    function doQuery($client, $filter, $property, $tstart, $tnow, $map) {
        $ret0 = NULL;
        $result = -1;
        $valueTimestamp = 0;

        if ($property == "") {
            return( array($result, $valueTimestamp) );
        }

        if ($map->get_hint("w4n_datasource_error")=='true') {
            # Bail out if previous query failed
            wm_debug ("W4N ReadData: Bail out due to a recent ReadData exception\n");
            return( array($result, $valueTimestamp) );
        }

        try {
            $ret0 = objtoarray($client->getObjectData(array(
              'filter' => $filter . " & (name=='$property')",
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

        if ($ret0 != NULL) {
            $ds0Array = $ret0['timeseries']['timeserie']; //['0']; // IN
            $ds0len   = $ds0Array['length'];
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

                $result = $ts0['v'];
                // Timestamp of data (assumed same for both datasources,
                // and ReadData does not allow to return two different timestamps anyway)
                $valueTimestamp = $ts0['t'];
            }
        }
        return array($result, $valueTimestamp);
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
