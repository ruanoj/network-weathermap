<?php
// require_once 'PHPUnit/Framework.php';

require_once dirname(__FILE__).'/../lib/HTML_ImageMap.class.php';

/**
 * Test class for HTML_ImageMap_Area_Circle.
 * Generated by PHPUnit on 2010-04-06 at 14:29:42.
 */
class HTML_ImageMap_Area_CircleTest extends PHPUnit_Framework_TestCase {
    /**
     * @var HTML_ImageMap_Area_Circle
     */
    protected $object;

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp() {
        $this->object = new HTML_ImageMap_Area_Circle("testname","testhref",array(array(100,100,150,100)));
    }

    /**
     * Tears down the fixture, for example, closes a network connection.
     * This method is called after a test is executed.
     */
    protected function tearDown() {
    }

    /**
     * @todo Implement testAsHTML().
     */
    public function testAsHTML() {
        // Remove the following lines when you implement this test.

        $output = $this->object->asHTML();
        $this->assertEquals('<area id="testname" href="testhref" shape="circle" coords="100,100,150,100" />', $output);
    }

    /**
     * @todo Implement testHitTest().
     */
    public function testHitTest() {

    $points = array(
        array(100,100,True),
        array(51,100,True),
        array(149,100,True),
        array(100,149,True),
        array(100,51,True),
        array(49,49,False),
        array(49,100,False),
        array(100,151,False),
        array(149,149,False)
    );

    foreach ($points as $point) {
        $desc = sprintf("Hit %d,%d", $point[0], $point[1]);
        $this->assertEquals($point[2],  $this->object->hitTest($point[0], $point[1]), $desc);
    }

    }
}
?>
