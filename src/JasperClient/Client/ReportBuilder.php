<?php

/**
 * @author David Cramblett
 */

namespace JasperClient\Client;

use JasperClient\Client\Report;
use JasperClient\Client\ReportLoader;
use JasperClient\Interfaces\InputControlAbstractFactory;

/**
 * Report Builder
 */
class ReportBuilder {

    ///////////////
    // CONSTANTS //
    ///////////////

    const FORMAT_HTML = 'html';
    const FORMAT_PDF = 'pdf';
    const FORMAT_XLS = 'xls';

    ///////////////
    // VARIABLES //
    ///////////////


    /**
     * Reference to the jasper client class
     * @var JasperClient\Client\Client
     */
    private $client;

    /**
     * Uri of the report this builder is for on the Jasper Server
     * @var string
     */
    private $reportUri;

    /**
     * Where to get the options for the input controls from
     * @var string
     */
    private $getICFrom;

    /**
     * Reference to the report's collection of input controls
     * @var array
     */
    private $reportInputControl;

    /**
     * Flag indicating whether the report has mandatory input or not
     * @var boolean
     */
    private $hasMandatoryInput;

    /**
     * Array of parameters keyed by name
     * @var array
     */
    private $params;

    /**
     * The format of the report (for when getting report output without caching)
     * @var string
     */
    private $format;

    /**
     * The url to append to assets
     * @var string
     */
    private $assetUrl;

    /**
     * The page number of the report (for when getting report output without caching and in html format)
     * @var int
     */
    private $page;

    /**
     * The range of pages to get in a cached or asynchronous report
     * @var string
     */
    private $pageRange;

    /**
     * An implementation of the InputControlAbstractFactory interface
     * @var JasperClient\Interfaces\InputControlAbstractFactory
     */
    private $inputControlFactory;

    /**
     * Where to cache reports
     *
     * @var string
     */
    private $reportCache;


    //////////////////
    // BASE METHODS //
    //////////////////


    /**
     * Constructor 
     * 
     * @param Client $client    Report client
     * @param string $reportUri Uri of the report on Jasper Server
     * @param string $getICFrom Where to get the options for the input controls
     * @param InputControlAbstractFacotry Optional implemention of the input control factory interface when building the input controls
     */
    function __construct(
        Client $client,
        $reportUri,
        $getICFrom = 'Jasper',
        InputControlAbstractFactory $inputControlFactory = null) {
        //Set stuff
        $this->client    = $client;
        $this->reportUri = $reportUri;
        $this->getICFrom = $getICFrom;
        $this->inputControlFactory = $inputControlFactory;

        //Init the params array
        $params = array();

        //Load the report input controls
        $this->loadInputControls();
    }


    ///////////////////
    // CLASS METHODS //
    ///////////////////


    /**
     * Loads the input controls for the requested report
     * 
     * @param  string $getICFrom Optional override to the location of the input control options
     * 
     * @return JasperClient\Client\AbstractInputControl The input contols for the requested report
     */
    public function loadInputControls($getICFrom = null) {
        //Set where to get the input controls options from
        $getICFrom = $getICFrom ?: $this->getICFrom;

        // Load report input controls
        $this->reportInputControl =
            $this->client->getReportInputControl(
                $this->reportUri,
                $getICFrom,
                $this->inputControlFactory
            );

        // Look for Mandatory Inputs
        $this->hasMandatoryInput = false;
        foreach ($this->reportInputControl as $key => $inputControl) {
            if ('true' == $inputControl->getMandatory()) {
                $this->hasMandatoryInput = true;
            }
        }

        //Return the loaded input controls
        return $this->reportInputControl;
    }


    /**
     * Sets the page range to get for a cached or asynchronous report
     * 
     * @param int $min First page to get
     * @param int $max Last page to get
     */
    public function setPageRange($min, $max) {
        $this->pageRange = $min . '-' . $max;
    }


    /**
     * Sets the input parameters array
     * 
     * @param array $params Parameters array keyed by the input parameter's label
     */
    public function setInputParametersArray($params = array()) {
        //Foreach value in the given array, set it
        foreach($params as $label => $values) {
            $this->setInputParameter($label, $values);
        }
    }


    /**
     * Sets an input parameter
     * 
     * @param string $label  The label of the input parameter
     * @param array  $values The array of values the parameter has
     *                       OR a single value 
     */
    public function setInputParameter($label, $values) {
        //Check if the values input is an array or not
        if (!is_array($values)) {
            //If not, make it an array
            $values = array($values);
        }

        //Set the params array
        $this->params[$label] = $values;
    }


    /**
     * Sends the report execution request to the jasper server and returns the request id
     * 
     * @param  array   $options Any additional options permitted by the Jasper Server rest v2 API
     * 
     * @return string           The request id of the report execution request
     */
    public function sendExecutionRequest($options = array()) {
        //Add the input parameters to the options array
        $options['parameters'] = $this->params;

        //Send the request to the client
        $rED = $this->client->startReportExecution($this->reportUri, $options);

        //Return the request id from the report execution details
        return JasperHelper::getRequestIdFromDetails($rED);
    }


    /**
     * Sends a report execution request (blocking call) and caches the report
     *
     * @param  array  $options Options array
     *
     * @return string          The request id of the cached report
     */
    public function runReport($options = array()) {
        //This does not work with async yet
        $options['async'] = false;

        //Get the request id
        $requestId = $this->sendExecutionRequest($options);
        
        //Tell the client to cache
        $this->client->cacheReportExecution($requestId, array('reportCacheDirectory' => $this->reportCache));

        //Return the request id
        return $requestId;
    }


    /**
     * Returns the requested report synchronously, without caching it
     * 
     * @return JasperClient\Client\Report The output
     */
    public function build() {
        //If format is html, add page to the params
        if (self::FORMAT_HTML == $this->format) {
            //Set page to 1 if its not set
            $this->page = $this->page ?: 1;

            //Add it to the params
            $this->params['page'] = $this->page;
        }

        //Get the report body from the client
        $this->reportOutput =
            $this->client->getReport(
                $this->reportUri,
                $this->format,
                $this->params,
                $this->assetUrl
            );

        //Construct a new report object
        $report = new Report($this->format, $this->page);

        // Look for report errors
        if (true == $this->reportOutput['error']) {

            //Get the error information and put it into a format that will print all pretty like
            $errorOuput = new \SimpleXMLElement($this->reportOutput['output']);

            if ( $errorOuput->parameters->parameter ) {
                $output  = "<div class=\"jrPage jrMessage\" >\n";
                $output .= "\t\t\t<div class=\"errorMesg\">\n";
                $output .= "\t\t\t\t<h1>Error</h1>" . $errorOuput->parameters->parameter . "\n";
                $output .= "\t\t\t</div>\n";
                $output .= "\t\t</div>\n";
            }
            else {
                $output  = "<div class=\"jrPage jrMessage\" >\n";
                $output .= "\t\t\t<div class=\"errorMesg\">\n";
                $output .= "\t\t\t\t<h1>Error</h1>" . $errorOuput->error[0]->defaultMessage . "\n";
                $output .= "\t\t\t</div>\n";
                $output .= "\t\t</div>\n";
            }
            
            //Set the report to be an error message container
            $report->setOutput($output);
            $report->setError(true);

            //Return the error in the report object
            return $report;
        } else {
            $report->setOutput($this->reportOutput['output']);
            $report->setError(false);
        }

        // If html format - Find number of pages
        //   This method is terrible, as it runs
        //   the report a second time. I don't
        //   know better way at the moment.
        if (self::FORMAT_HTML == $this->format) {

            $xmlOutput =
                $this->client->getReport(
                    $this->reportUri,
                    'xml',
                    $this->params
                );

            $objectOutput = new \SimpleXMLElement($xmlOutput['output']);

            foreach ($objectOutput->property as $object) {
                if('net.sf.jasperreports.export.xml.page.count' == $object->attributes()->name) {
                    $report->setTotalPages((string)$object->attributes()->value);
                }
            }
        }

        return $report;
    }


    /////////////////////////
    // GETTERS AND SETTERS //
    /////////////////////////


    /**
     * Gets the Reference to the jasper client class.
     *
     * @return JasperClient\Client\Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Sets the Reference to the jasper client class.
     *
     * @param JasperClient\Client\Client $client the client
     *
     * @return self
     */
    public function setClient(JasperClient\Client\Client $client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Gets the Uri of the report this builder is for on the Jasper Server.
     *
     * @return string
     */
    public function getReportUri()
    {
        return $this->reportUri;
    }

    /**
     * Sets the Uri of the report this builder is for on the Jasper Server.
     *
     * @param string $reportUri the report uri
     *
     * @return self
     */
    public function setReportUri($reportUri)
    {
        $this->reportUri = $reportUri;

        return $this;
    }


    /**
     * Gets the Where to get the options for the input controls from.
     *
     * @return string
     */
    public function getGetICFrom()
    {
        return $this->getICFrom;
    }

    /**
     * Sets the Where to get the options for the input controls from.
     *
     * @param string  $getICFrom The location to the input controls from
     * @param boolean $reload    Whether to reload the input controls
     *
     * @return self
     */
    public function setGetICFrom($getICFrom, $reload = false)
    {
        $this->getICFrom = $getICFrom;

        if ($reload) {
            $this->loadInputControls();
        }

        return $this;
    }

    /**
     * Gets the Reference to the report's collection of input controls.
     *
     * @return array
     */
    public function getReportInputControl()
    {
        return $this->reportInputControl;
    }

    /**
     * Sets the Reference to the report's collection of input controls.
     *
     * @param array $reportInputControl the report input control
     *
     * @return self
     */
    public function setReportInputControl($reportInputControl)
    {
        $this->reportInputControl = $reportInputControl;

        return $this;
    }

    /**
     * Gets the Flag indicating whether the report has mandatory input or not.
     *
     * @return boolean
     */
    public function getHasMandatoryInput()
    {
        return $this->hasMandatoryInput;
    }

    /**
     * Sets the Flag indicating whether the report has mandatory input or not.
     *
     * @param boolean $hasMandatoryInput the has mandatory input
     *
     * @return self
     */
    public function setHasMandatoryInput($hasMandatoryInput)
    {
        $this->hasMandatoryInput = $hasMandatoryInput;

        return $this;
    }

    /**
     * Gets the Array of parameters keyed by name.
     *
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * Returns the format
     * 
     * @return string Format to get a report in if not caching
     */
    public function getFormat() {
        return $this->format;
    }

    /**
     * Set the format to get a report in if not caching
     * 
     * @param string $format Format to get a non-cached report in
     *
     * @return self
     */
    public function setFormat($format) {
        $this->format = $format;

        return $this;
    }

    /**
     * Get the page to return a non-cached html report's ouput on
     *  
     * @return int Page number
     */
    public function getPage() {
        return $this->page;
    }

    /**
     * Set the page number to return if getting a non-cached report in html format
     * 
     * @param int $page Page number
     *
     * @return self
     */
    public function setPage($page) {
        $this->page = $page;

        return $this;
    }

    /**
     * Get the url to append to assets in html reports
     *  
     * @return string assetUrl The url to append in string form
     */
    public function getAssetUrl() {
        return $this->assetUrl;
    }

    /**
     * Set the url to append to assets in html reports
     * 
     * @param string $assetUrl The url to append in string form
     *
     * @return self
     */
    public function setAssetUrl($assetUrl) {
        $this->assetUrl = $assetUrl;

        return $this;
    }

    /**
     * Get the input control factory
     * 
     * @return JasperClient\Interfaces\InputControlAbstractFactory The input control factory
     */
    public function getInputControlFactory() {
        return $this->inputControlFactory;
    }

    /**
     * Set the input control factory
     * 
     * @param  JasperClient\Interfaces\InputControlAbstractFactory $inputControlFactory Implementation of the input control abstract 
     *                                                                                  factory to use to build the input controls
     *
     * @return self
     */
    public function setInputControlFactory(JasperClient\Interfaces\InputControlAbstractFactory $inputControlFactory) {
        $this->inputControlFactory = $inputControlFactory;

        return $this;
    }


    /**
     * Gets the Where to cache reports.
     *
     * @return string
     */
    public function getReportCache()
    {
        return $this->reportCache;
    }

    /**
     * Sets the Where to cache reports.
     *
     * @param string $reportCache the report cache
     *
     * @return self
     */
    public function setReportCache($reportCache)
    {
        $this->reportCache = $reportCache;

        return $this;
    }
}