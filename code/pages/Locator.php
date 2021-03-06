<?php

/**
 * Class Locator
 *
 * @property bool $AutoGeocode
 * @property bool $ModalWindow
 * @property string $Unit
 * @method Categories|ManyManyList $Categories
 */
class Locator extends Page
{

    /**
     * @var array
     */
    private static $db = array(
        'Unit' => 'Enum("m,km","m")',
    );

    /**
     * @var array
     */
    private static $many_many = array(
        'Categories' => 'LocationCategory',
    );

    /**
     * @var string
     */
    private static $singular_name = 'Locator';
    /**
     * @var string
     */
    private static $plural_name = 'Locators';
    /**
     * @var string
     */
    private static $description = 'Find locations on a map';

    /**
     * @var string
     */
    private static $location_class = 'Location';

    /**
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        // Settings
        $fields->addFieldsToTab('Root.Settings', array(
            HeaderField::create('DisplayOptions', 'Display Options', 3),
            OptionsetField::create('Unit', 'Unit of measure', array('m' => 'Miles', 'km' => 'Kilometers')),
        ));

        // Filter categories
        $config = GridFieldConfig_RelationEditor::create();
        if (class_exists('GridFieldAddExistingSearchButton')) {
            $config->removeComponentsByType('GridFieldAddExistingAutocompleter');
            $config->addComponent(new GridFieldAddExistingSearchButton());
        }
        $categories = $this->Categories();
        $categoriesField = GridField::create('Categories', 'Categories', $categories, $config)
            ->setDescription('only show locations from the selected category');

        // Filter
        $fields->addFieldsToTab('Root.Filter', array(
            HeaderField::create('CategoryOptionsHeader', 'Location Filtering', 3),
            $categoriesField,
        ));

        $this->extend('updateCMSFields', $fields);

        return $fields;
    }

    /**
     * @param array $filter
     * @param array $filterAny
     * @param array $exclude
     * @param null|callable $callback
     * @return DataList|ArrayList
     */
    public static function get_locations(
        $filter = [],
        $filterAny = [],
        $exclude = [],
        $callback = null
    )
    {
        $locationClass = Config::inst()->get('Locator', 'location_class');
        $locations = $locationClass::get()->filter($filter)->exclude($exclude);

        if (!empty($filterAny)) {
            $locations = $locations->filterAny($filterAny);
        }
        if (!empty($exclude)) {
            $locations = $locations->exclude($exclude);
        }

        if ($callback !== null && is_callable($callback)) {
            $locations->filterByCallback($callback);
        }

        return $locations;
    }

    /**
     * @return DataList
     */
    public static function get_all_categories()
    {
        return LocationCategory::get();
    }

    /**
     * @return bool
     */
    public function getPageCategories()
    {
        return self::locator_categories_by_locator($this->ID);
    }

    /**
     * @param int $id
     * @return bool|
     */
    public static function locator_categories_by_locator($id = 0)
    {
        if ($id == 0) {
            return false;
        }

        return Locator::get()->byID($id)->Categories();
    }


}

/**
 * Class Locator_Controller
 */
class Locator_Controller extends Page_Controller
{
    /**
     * @var array
     */
    private static $allowed_actions = array(
        'xml',
    );

    /**
     * @var array
     */
    private static $base_filter = [];

    /**
     * @var array
     */
    private static $base_exclude = [
        'Lat' => 0,
        'Lng' => 0,
    ];

    /**
     * @var array
     */
    private static $base_filter_any = [];

    /**
     * @var string
     */
    private static $list_template_path = 'locator/templates/location-list-description.html';

    /**
     * @var string
     */
    private static $info_window_template_path = 'locator/templates/infowindow-description.html';

    /**
     * @var bool
     */
    private static $bootstrapify = true;

    /**
     * @var int
     */
    private static $limit = 50;

    /**
     * ID of map container
     *
     * @var string
     */
    private static $map_container = 'map';

    /**
     * class of location list container
     *
     * @var string
     */
    private static $list_container = 'loc-list';

    /**
     * GET variable which, if isset, will trigger storeLocator init and return XML
     *
     * @var string
     */
    private static $query_trigger = 'action_doFilterLocations';

    /**
     * @var DataList|ArrayList
     */
    protected $locations;

    /**
     * Set Requirements based on input from CMS
     */
    public function init()
    {
        parent::init();

        // prevent init of map if no query
        $request = Controller::curr()->getRequest();
        if ($this->getTrigger($request)) {
            // google maps api key
            $key = Config::inst()->get('GoogleGeocoding', 'google_api_key');

            $locations = $this->getLocations();

            if ($locations) {

                Requirements::css('locator/css/map.css');
                Requirements::javascript('framework/thirdparty/jquery/jquery.js');
                Requirements::javascript('https://maps.google.com/maps/api/js?key=' . $key);
                Requirements::javascript('locator/thirdparty/jquery-store-locator-plugin/assets/js/libs/handlebars.min.js');
                Requirements::javascript('locator/thirdparty/jquery-store-locator-plugin/assets/js/plugins/storeLocator/jquery.storelocator.js');

                $featuredInList = ($locations->filter('Featured', true)->count() > 0);
                $defaultCoords = $this->getAddressSearchCoords() ? $this->getAddressSearchCoords() : '';

                $featured = $featuredInList
                    ? 'featuredLocations: true'
                    : 'featuredLocations: false';

                // map config based on user input in Settings tab
                $limit = Config::inst()->get('Locator_Controller', 'limit');
                if ($limit < 1) $limit = -1;
                $load = 'fullMapStart: true, storeLimit: ' . $limit . ', maxDistance: true,';

                $listTemplatePath = Config::inst()->get('Locator_Controller', 'list_template_path');
                $infowindowTemplatePath = Config::inst()->get('Locator_Controller', 'info_window_template_path');

                $kilometer = ($this->data()->Unit == 'km') ? "lengthUnit: 'km'" : "lengthUnit: 'm'";

                // pass GET variables to xml action
                $vars = $this->request->getVars();
                unset($vars['url']);
                $url = '';
                if (count($vars)) {
                    $url .= '?' . http_build_query($vars);
                }
                $link = Controller::join_links($this->Link(), 'xml.xml', $url);

                // containers
                $map_id = Config::inst()->get('Locator_Controller', 'map_container');
                $list_class = Config::inst()->get('Locator_Controller', 'list_container');

                // init map
                Requirements::customScript("
                $(function(){
                    $('#map-container').storeLocator({
                        " . $load . "
                        dataLocation: '" . $link . "',
                        listTemplatePath: '" . $listTemplatePath . "',
                        infowindowTemplatePath: '" . $infowindowTemplatePath . "',
                        originMarker: true,
                        " . $featured . ",
                        slideMap: false,
                        distanceAlert: -1,
                        " . $kilometer . ",
                        " . $defaultCoords . "
                        mapID: '" . $map_id . "',
                        locationList: '" . $list_class . "',
                        mapSettings: {
							zoom: 12,
							mapTypeId: google.maps.MapTypeId.ROADMAP,
							disableDoubleClickZoom: true,
							scrollwheel: false,
							navigationControl: false,
							draggable: false
						}
                    });
                });
            ", "locator_map_init_script");
            }
        }
    }

    /**
     * @param SS_HTTPRequest $request
     *
     * @return ViewableData_Customised
     */
    public function index(SS_HTTPRequest $request)
    {
        if ($this->getTrigger($request)) {
            $locations = $this->getLocations();
        } else {
            $locations = ArrayList::create();
        }

        return $this->customise(array(
            'Locations' => $locations,
        ));
    }

    /**
     * Return a XML feed of all locations marked "show in locator"
     *
     * @param SS_HTTPRequest $request
     * @return HTMLText
     */
    public function xml(SS_HTTPRequest $request)
    {
        if ($this->getTrigger($request)) {
            $locations = $this->getLocations();
        } else {
            $locations = ArrayList::create();
        }

        return $this->customise(array(
            'Locations' => $locations,
        ))->renderWith('LocationXML');
    }

    /**
     * @return ArrayList|DataList
     */
    public function getLocations()
    {
        if (!$this->locations) {
            $this->setLocations($this->request);
        }
        return $this->locations;
    }

    /**
     * @param SS_HTTPRequest|null $request
     * @return $this
     */
    public function setLocations(SS_HTTPRequest $request = null)
    {

        if ($request === null) {
            $request = $this->request;
        }
        $filter = $this->config()->get('base_filter');

        if ($request->getVar('CategoryID')) {
            $filter['CategoryID'] = $request->getVar('CategoryID');
        } else {
            if ($this->getPageCategories()->exists()) {
                foreach ($this->getPageCategories() as $category) {
                    $filter['CategoryID'][] = $category->ID;
                }
            }
        }

        $this->extend('updateLocatorFilter', $filter, $request);

        $filterAny = $this->config()->get('base_filter_any');
        $this->extend('updateLocatorFilterAny', $filterAny, $request);

        $exclude = $this->config()->get('base_exclude');
        $this->extend('updateLocatorExclude', $exclude, $request);

        $locations = Locator::get_locations($filter, $filterAny, $exclude);
        $locations = DataToArrayListHelper::to_array_list($locations);

        //allow for adjusting list post possible distance calculation
        $this->extend('updateLocationList', $locations);

        if ($locations->canSortBy('distance')) {
            $locations = $locations->sort('distance');
        }

        if (Config::inst()->get('LocatorForm', 'show_radius')) {
            if ($radius = (int)$request->getVar('Radius')) {
                $locations = $locations->filterByCallback(function ($location) use (&$radius) {
                    return $location->distance <= $radius;
                });
            }
        }

        //allow for returning list to be set as
        $this->extend('updateListType', $locations);

        $limit = Config::inst()->get('Locator_Controller', 'limit');
        if ($limit > 0) {
            $locations = $locations->limit($limit);
        }

        $this->locations = $locations;
        return $this;

    }

    /**
     * @param SS_HTTPRequest $request
     * @return bool
     */
    public function getTrigger(SS_HTTPRequest $request = null)
    {
        if ($request === null) {
            $request = $this->getRequest();
        }
        $trigger = $request->getVar(Config::inst()->get('Locator_Controller', 'query_trigger'));
        return isset($trigger);
    }

    /**
     * @return bool|string
     */
    public function getAddressSearchCoords()
    {
        if (!$this->request->getVar('Address')) {
            return false;
        }
        $coords = GoogleGeocoding::address_to_point(Controller::curr()->getRequest()->getVar('Address'));

        $lat = $coords['lat'];
        $lng = $coords['lng'];

        return "defaultLat: {$lat}, defaultLng: {$lng},";
    }

    /**
     * LocationSearch form.
     *
     * Search form for locations, updates map and results list via AJAX
     *
     * @return Form
     */
    public function LocationSearch()
    {

        $form = LocatorForm::create($this, 'LocationSearch');
        if (class_exists('BootstrapForm') && $this->config()->get('bootstrapify')) {
            $form->Fields()->bootstrapify();
            $form->Actions()->bootstrapify();
        }

        return $form
            ->setFormMethod('GET')
            ->setFormAction($this->Link())
            ->disableSecurityToken()
            ->loadDataFrom($this->request->getVars());
    }

}
