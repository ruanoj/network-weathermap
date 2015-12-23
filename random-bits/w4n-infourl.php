<?php
// infourl is a quick hack to provide accumulated data of the last
// 4, 24, 48 hours, last week, month, 3 months and year of a given
// device and part
//
// It invokes w4n-overlibgraph.php as img source
// Based on EMC M&R/Watch4net Frontend Web Service Developer Tutorial sample code
// Adapted by @jonruano
// https://github.com/ruanoj/network-weathermap
// Released under the GNU Public License

$filter = '';
$name = '';
$end = 0;
$start = 0;
$width = 500;
$height = 300;
$scale = 1.0;

// retrieve filter param
if(isset($_GET['filter'])) {
  $filter = $_GET['filter'];
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
// retrieve specific property
if(isset($_GET['name'])) {
  $name = $_GET['name'];
}

$_printableFilter = "$filter&name=$name";
$_baseURL = "w4n-overlibgraph.php?filter=".urlencode($filter)."&width=$width&height=$height&name=".urlencode($name)."&scale=".urlencode($scale);
?>
<html><head><title><?= $_printableFilter; ?> - Statistics</title>
<body bgcolor="#ffffff">
<h3><?= $_printableFilter; ?></h3>
<p>Last four hours:<br/>
<img border="0" src="<?= $_baseURL; ?>&start=-14400" /></p>
<p>Last 24 hours:<br/>
<img border="0" src="<?= $_baseURL; ?>&start=-86400" /></p>
<p>Last 48 hours:<br/>
<img border="0" src="<?= $_baseURL; ?>&start=-172800" /></p>
<p>Last week:<br/>
<img border="0" src="<?= $_baseURL; ?>&start=-604800" /></p>
<!-- The following take longer or time out. Perhaps w4n-overlibgraph should call a different function -->
<p>Last month:<br/>
<img border="0" src="<?= $_baseURL; ?>&start=-2592000" /></p>
<p>Last 3 months:<br/>
<img border="0" src="<?= $_baseURL; ?>&start=-7776000" /></p>
<p>Last year:<br/>
<img border="0" src="<?= $_baseURL; ?>&start=-30758400" /></p>
<hr />
</body></html>
