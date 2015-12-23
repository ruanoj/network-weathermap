<?php
// Overlib graph helper for EMC M&R/Watch4net
// Based on Frontend Web Service Developer Tutorial sample code
// Adapted by @jonruano
// https://github.com/ruanoj/network-weathermap
// Released under the GNU Public License
/*

  This is a simple hook into W4N to, by default, retrieve ifInOctets and ifOutOctets 
  and graph them as Gigabits per second, provided as a complement to the W4N datasource.

  Assuming you have defined the hints "source_router" and "source_interface" on a LINK
  entry, the OVERLIBGRAPH would be something like this:

  OVERLIBGRAPH random-bits/w4n-overlibgraph.php?filter=device%3D%3D%27{link:this:source_router}%27%26part%3D%3D%27{link:this:source_interface}%27

  Note that as a URL, it needs to be provided encoded. The raw parameter is
  filter=device=='devicename'&part=='interface'.

  If other than ifInOctets/ifOutOctets are required, put the expression as 'name' HTTP
  FORM parameter. Unit conversion to 'Gbps' is not done if 'name' is non-empty.

 */

// The following definitions need to be amended before use

// WSDLs
// Amend the URL below to point to your W4N server
define('REP_WS_WSDL',     'http://localhost:58080/APG-WS/wsapi/report?wsdl');
define('REP_INL_WS_WSDL', 'http://localhost:58080/APG-WS/wsapi/report_inline?wsdl');
define('DB_WS_WSDL',      'http://localhost:58080/APG-WS/wsapi/db?wsdl');

// Credentials
// These credentials will be used to connect to W4N Web Service
define('WS_USER',         'username');
define('WS_PASSWORD',     'password');

// You needn't modify anything below this line


// W4N XML Namespaces
define('XML_TREE_NS',     'http://www.watch4net.com/APG/Web/XmlTree1');
define('XML_REPORT_NS',   'http://www.watch4net.com/APG/Web/XmlReport1');
define('REP_WS_NS',       'http://www.watch4net.com/APG/Remote/ReportManagerService');


Header('Content-type: image/png');

$filter = '';
$name = '';
$end = 0;
$start = 0;
$width = 500;
$height = 300;
$scale = 1.0;
$convertUnits = TRUE;

// retrieve filter param
if(isset($_GET['filter'])) {
  $filter = $_GET['filter'];
}
if (get_magic_quotes_gpc()) {
    $filter = stripslashes($filter);
}
if(isset($_GET['name']) && $_GET['name']!="") {
  $name = "&(name=='".stripslashes($_GET['name'])."')";
  $convertUnits = FALSE;
} else {
  $name = "&(name=='ifInOctets' | name=='ifOutOctets')";
}
if(strlen($filter) == 0) {
  $filter = '!(*)';
} else {
  $filter = $filter . $name;
}
// retrieve end timestamp param
if(isset($_GET['end'])) {
  $end = $_GET['end'];
}
if($end == 0) {
    $end = time();
} else if($end < 0) {
    $end = time() + $end;
}
// retrieve start timestamp param
if(isset($_GET['start'])) {
  $start = $_GET['start'];
}
if($start == 0) {
    $start = $end - 86400;
} else if($start < 0) {
    $start = $end + $start;
}
// retrieve width param
if(isset($_GET['width'])) {
  $width = $_GET['width'];
}
// retrieve width param
if(isset($_GET['height'])) {
  $height = $_GET['height'];
}
// retrieve scale param
if(isset($_GET['scale'])) {
  $scale = $_GET['scale'];
}

// create the SoapClient object using the WSDL location and the service credentials
$client = new SoapClient(REP_INL_WS_WSDL, array('login' => WS_USER, 'password' => WS_PASSWORD));
// setup some report properties
$properties = array(
    'property' => array(
        // set the graph width
        array('key' => 'apg.ws.report.graph.width', '_' => $width),
        // set the graph height
        array('key' => 'apg.ws.report.graph.height', '_' => $height),
        // set the graph scaling factor
        array('key' => 'apg.ws.report.graph.scale', '_' => $scale),
        // we want a graph image...
        array('key' => 'apg.ws.report.graph.with.image', '_' => 'true'),
        // ... with the legend inside
        array('key' => 'apg.ws.report.graph.with.legend.image', '_' => 'true')
    )
);

// This is a workaround to the fact that I couldn't find a way to put two formulas under 'formula'
// on the node, as it did not like any array.
// These formulas will convert from Octets/s to Gbits/s
$formulae=new SoapVar(
      '<ns2:formula formulaId="math.Division">'
    . '<setting name="scale" value="1"/>'
    . '<parameter name="numerator" xsi:type="FilterFormulaParameterDefinition" filter="name==\'ifInOctets\'"/>'
    . '<parameter name="denominator" xsi:type="ConstantFormulaParameterDefinition" value="1.25E8"/>'
    . '<result name="Incoming (Gbits/sec)" default="false" graphable="true"/>'
    . '</ns2:formula>'

    . '<ns2:formula formulaId="math.Division">'
    . '<setting name="scale" value="1"/>'
    . '<parameter name="numerator" xsi:type="FilterFormulaParameterDefinition" filter="name==\'ifOutOctets\'"/>'
    . '<parameter name="denominator" xsi:type="ConstantFormulaParameterDefinition" value="1.25E8"/>'
    . '<result name="Outgoing (Gbits/sec)" default="false" graphable="true"/>'
    . '</ns2:formula>'
    , XSD_ANYXML, null, XML_TREE_NS);

// NODE - create an XML Tree template
$node = array(
    'property' => array(
         new SoapVar(
             // set the filter expression
             array('filterExpression' => $filter),
                 XSD_ANYTYPE, 'NodeFilter', XML_TREE_NS),
        new SoapVar(
            // set the report mode
            array('defaultMode' => 'nrx'),
                XSD_ANYTYPE, 'ReportPreferences', XML_TREE_NS),
        new SoapVar(
            // set the selected time range
            array('timeRangeExpression' => 'r:' . $start . ':' . $end . ':0'),
                XSD_ANYTYPE, 'RuntimePreferences', XML_TREE_NS)
    )
);
# Add conversion if required
if ($convertUnits == TRUE) {
  $node['formula']=$formulae;
}

// call the service and convert objects to array
$response = objtoarray($client->getReport(array( 'properties' => $properties, 'node' => $node)));

// forward graph data to the client
print($response['graph-element']['graph']);

// END of main program


// Helper functions

// simple function to convert object to array
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

// objtoarray($object, &$restructarray) function wrapper
function objtoarray($object) {
    $ret = array();
    objtoarray_rec($object, $ret);
    return $ret;
}
?>
