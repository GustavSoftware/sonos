<?php

namespace duncan3dc\Sonos;

use duncan3dc\DomParser\XmlParser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Make http requests to a Sonos device.
 */
class Device
{
    /**
     * @var string $ip The IP address of the speaker.
     */
    public $ip;

    /**
     * @var array $cache Cached data to increase performance.
     */
    protected $cache = [];

    /**
     * @var LoggerInterface $logger The logging object
     */
    protected $logger;


    /**
     * Create an instance of the Device class.
     *
     * @param string $ip The ip address that the speaker is listening on
     * @param LoggerInterface $logger A logging object
     */
    public function __construct($ip, LoggerInterface $logger = null)
    {
        $this->ip = $ip;

        if ($logger === null) {
            $logger = new NullLogger;
        }
        $this->logger = $logger;
    }


    /**
     * Retrieve some xml from the speaker.
     *
     * @param string $url The url to retrieve
     *
     * @return XmlParser
     */
    public function getXml($url)
    {
        if (!isset($this->cache[$url])) {
            $uri = "http://{$this->ip}:1400{$url}";
            $this->logger->info("requesting xml from: {$uri}");
            $this->cache[$url] = new XmlParser($uri);
        }

        return $this->cache[$url];
    }


    /**
     * Send a soap request to the speaker.
     *
     * @param string $service The service to send the request to
     * @param string $action The action to call
     * @param array $params The parameters to pass
     *
     * @return mixed
     */
    public function soap($service, $action, $params = [])
    {
        switch ($service) {
            case "AVTransport";
            case "RenderingControl":
                $path = "MediaRenderer";
                break;
            case "ContentDirectory":
                $path = "MediaServer";
                break;
            case "AlarmClock":
                $path = null;
                break;
            default:
                throw new \InvalidArgumentException("Unknown service: {$service}");
        }

        $location = "http://{$this->ip}:1400/";
        if ($path) {
            $location .= "{$path}/";
        }
        $location .= "{$service}/Control";

        $this->logger->info("sending soap request to: {$location}");

        $soap = new \SoapClient(null, [
            "location"  =>  $location,
            "uri"       =>  "urn:schemas-upnp-org:service:{$service}:1",
            "trace"     =>  true,
        ]);

        $soapParams = [];
        $params["InstanceID"] = 0;
        foreach ($params as $key => $val) {
            $soapParams[] = new \SoapParam(new \SoapVar($val, \XSD_STRING), $key);
        }

        try {
            return $soap->__soapCall($action, $soapParams);
        } catch (\SoapFault $e) {
            throw new Exceptions\SoapException($e, $soap);
        }
    }


    /**
     * Check if this sonos device is a speaker.
     *
     * @return bool
     */
    public function isSpeaker()
    {
        $parser = $this->getXml("/xml/device_description.xml");

        if (!$device = $parser->getTag("device")) {
            return false;
        }
        if (!$model = (string) $device->getTag("modelNumber")) {
            return false;
        }

        return in_array($model, ["S1", "S3", "S5"], true);
    }
}
