<?php
if(PHP_SAPI != 'cli')
	echo '<pre>';

require_once 'vendor/autoload.php';

///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
class oo_curl {
	private $ch;
	public $use_cache = true;

	public function __construct() {
		$this->ch = curl_init();
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, false);
	}

	public function get($url) {
		if($this->use_cache) {
			$filename = 'cache' . DIRECTORY_SEPARATOR . md5($url) . '.html';
			if(is_file($filename)) {
				return file_get_contents($filename);
			}
		}

		curl_setopt($this->ch, CURLOPT_URL, $url);
		$html = curl_exec($this->ch);
		file_put_contents($filename, $html);
		return $html;
	}
}
///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

use \cookieguru\phpgtfs\Writer;
define('BASE', 'https://msshuttle.mobi');
$curl = new oo_curl();

if(!is_dir('cache'))
	mkdir('cache');
if(!is_dir('dist'))
	mkdir('dist');

///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

$agencies = new \cookieguru\phpgtfs\gtfs\Agencies();
$agency = new \cookieguru\phpgtfs\model\Agency();
$agency->agency_name = 'Microsoft Shuttles';
$agency->agency_url = BASE . '/';
$agency->agency_timezone = 'America/Los_Angeles';
$agency->agency_lang = 'en';
$agencies->add($agency);

///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

$calendar = new \cookieguru\phpgtfs\gtfs\Calendar();
$service = new \cookieguru\phpgtfs\model\Calendar();
$service->service_id = 1;
$service->monday = 1;
$service->tuesday = 1;
$service->wednesday = 1;
$service->thursday = 1;
$service->friday = 1;
$service->saturday = 0;
$service->sunday = 0;
$service->start_date = '20000101';
$service->end_date = '20201231';
$calendar->add($service);

///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

use \cookieguru\phpgtfs\model\Route;
$doc = new DOMDocument();
$doc->preserveWhiteSpace = false;
$doc->loadHTML($curl->get(BASE . '/FixedRoute/FixedRouteScheduleList'));
$xpath = new DOMXPath($doc);
$entries = $xpath->query('//ul');

$children = $entries->item(0)->childNodes;

$routes = new \cookieguru\phpgtfs\gtfs\Routes();
foreach($children as $child) {
	if(isset($child->tagName) && $child->tagName == 'li') {
		$link = $xpath->query('a', $child)->item(0);
		parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query_parts);
		$route = new Route();
		$route->route_id = $query_parts['rid'];
		$route->route_long_name = trim($link->textContent);
		$route->route_type = 3; //bus
		$route->route_url = BASE . $link->getAttribute('href');
		$routes->add($route);
	}
}

///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

use \cookieguru\phpgtfs\model\Stop;
use \cookieguru\phpgtfs\model\Shape;
$stops = new \cookieguru\phpgtfs\gtfs\Stops();
$shapes = new \cookieguru\phpgtfs\gtfs\Shapes();

foreach($routes as $route) {
	$html = $curl->get(BASE . "/FixedRoute/FixedRouteTrack?routeID={$route->route_id}&routeName=" . urlencode($route->route_long_name) . '&ShowVehicles=False');

	preg_match('/waypoints\s*=\s*(\[.*\]);*.*/', $html, $matches);
	if(isset($matches[1])) {
		$waypoints = json_decode($matches[1]);
		foreach($waypoints as $waypoint) {
			$stop = new Stop;
			$stop->stop_id = $waypoint->ID;
			$stop->stop_name = $waypoint->Name;
			$stop->stop_desc = $waypoint->Description;
			$stop->stop_lat = $waypoint->XYCoords->Latitude;
			$stop->stop_lon = $waypoint->XYCoords->Longitude;
			$stops->add($stop);
		}
	}

	preg_match('/mapcoords\s*=\s*(\[.*\]);*.*/', $html, $matches);
	if(isset($matches[1])) {
		$coords = json_decode($matches[1]);
		foreach($coords as $coord) {
			$shape = new Shape();
			$shape->shape_id = "{$route->route_id}_shp";
			$shape->shape_pt_lat = $coord->Latitude;
			$shape->shape_pt_lon = $coord->Longitude;
			$shape->shape_pt_sequence = $coord->SortOrder;
			$shapes->add($shape);
		}
	}
}

///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

use \cookieguru\phpgtfs\model\Trip;
use \cookieguru\phpgtfs\model\StopTime;
$trips = new \cookieguru\phpgtfs\gtfs\Trips();
$stop_times = new \cookieguru\phpgtfs\gtfs\StopTimes();

$trip_id = 0;
foreach($routes as $route) {
	//What is rmid?  0 shows a few trips, 1 shows more.  But 1 doesn't show some of the trips that 0 does, and we get some duplicates when using both...
	$html = $curl->get(BASE . "/FixedRoute/FixedRouteSchedule?rid={$route->route_id}&rmid=0&rn=" . urlencode($route->route_long_name));
	blergh($html, $route, $shapes, $stops, $stop_times, $trips);
	$html = $curl->get(BASE . "/FixedRoute/FixedRouteSchedule?rid={$route->route_id}&rmid=1&rn=" . urlencode($route->route_long_name));
	blergh($html, $route, $shapes, $stops, $stop_times, $trips);
}
//Wouldn't it be nice if I wrote this script better, and didn't have to rely on global scope for everything?
function blergh($html, $route, $shapes, $stops, $stop_times, $trips) {
	global $trip_id;
	$doc = new DOMDocument();
	$doc->preserveWhiteSpace = false;
	$doc->loadHTML($html);
	$xpath = new DOMXPath($doc);
	foreach($xpath->query('//table')->item(0)->childNodes as $row) {
		$trip = new Trip();
		$trip->route_id = $route->route_id;
		$trip->service_id = 1;
		$trip->trip_id = ++$trip_id;
		$trip->trip_headsign = $route->route_long_name;
		$trip->shape_id = $shapes->existsByID("{$route->route_id}_shp") ? "{$route->route_id}_shp" : null;

		$i = 0;
		foreach($row->childNodes as $td) {
			if(!isset($td->tagName))
				continue;
			if(!isset($route_stops)) {
				$route_stops = [];
				unset($trip);
				$td = $td->nextSibling; //skip the first cell; it's empty
				while($td->nextSibling) {
					if(isset($td->tagName)) {
						$route_stops[$i++] = $stops->findByName($td->textContent)->stop_id;
					}
					$td = $td->nextSibling;
				}
				break;
			}

			if(!isset($trip->trip_short_name)) { //first column will have trip name
				$trip->trip_short_name = $trip->block_id = trim($td->textContent);
				continue;
			}

			if(strpos($td->textContent, ':') === false) {
				$i++;
				continue;
			}

			$stop_time = new StopTime();
			$stop_time->trip_id = $trip->trip_id;
			$stop_time->arrival_time = $stop_time->departure_time = date('H:i:s', strtotime($td->textContent));
			$stop_time->stop_id = $route_stops[$i];
			$stop_time->stop_sequence = ++$i;

			$stop_times->add($stop_time);
		}

		if(isset($trip)) {
			$trips->add($trip);
		}
	}
}

///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

$feed_information = new \cookieguru\phpgtfs\gtfs\FeedInfo();
$feed_info = new \cookieguru\phpgtfs\model\FeedInfo();
$feed_info->feed_publisher_name = 'cookieguru';
$feed_info->feed_publisher_url = 'https://github.com/cookieguru/';
$feed_info->feed_lang = 'en';
$feed_information->add($feed_info);

///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
x:
Writer::write('dist/', $agencies);
Writer::write('dist/', $calendar);
Writer::write('dist/', $routes);
Writer::write('dist/', $stops);
Writer::write('dist/', $shapes);
Writer::write('dist/', $trips);
Writer::write('dist/', $stop_times);
Writer::write('dist/', $feed_information);

$zip = new ZipArchive();
$zip->open('dist/gtfs_' . date('YmdHis') . '.zip', ZipArchive::OVERWRITE);
foreach(glob('dist' . DIRECTORY_SEPARATOR . '*.txt') as $file) {
	$zip->addFile($file, basename($file));
}
echo "Saved {$zip->numFiles} files to {$zip->filename}";
$zip->close();