<?php
/**
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaCode <info@prestacode.de>
*  @copyright 20010-2015 PrestaCode
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_'))
exit;
include_once('./classes/Cookie.php');
$cookie = new Cookie('ps');

class GoogleShopping extends Module
{
private $_html = '';
private $_postErrors = array();
private $_cookie;
private $_compat;
private $_warnings;
private $_mod_errors;
private $xml_description;
private $psdir;
private $id_lang;
private $languages;
private $lang_iso;
private $id_currency;
private $currencies;
private $gtin_field;
private $use_supplier;
private $nearby;
public $item_data = '';
public function __construct()
	{
	$version_mask = explode('.', _PS_VERSION_, 2);
	$this->_compat = (int)implode('', $version_mask);
	$this->_warnings = array();
	$this->_mod_errors = array();
	$this->name = 'googleshopping';
	$this->tab = 'advertising_marketing';
	$this->author = 'www.prestacode.de';
	$this->version = '1.1.3';
	$this->module_key = 'bd10bbdb995398cf1fb81f61c63f54c3';
	parent::__construct();
// Set default config values if they don't already exist (here for compatibility in case the user doesn't uninstall/install at upgrade)
// Also set global "macro" data for the feed and check for store configuration changes
	if ($this->isInstalled($this->name))
	{
	// deprecated
	if (Configuration::get($this->name.'_domain'))
Configuration::deleteByName($this->name.'_domain');
// deprecated
if (Configuration::get($this->name.'_psdir'))
Configuration::deleteByName($this->name.'_psdir');
$this->_setDefaults();
}
$this->displayName = $this->l('Google Base Produkt Feed');
$this->description = $this->l('Genereriert Ihren Google Shopping Produkt Feed per Mausklick. www.prestacode.de');
}
public function install()
	{
		$this->_setDefaults();
		return parent::install();
		
	}
private function _setDefaults()
{
	if (!Configuration::get($this->name.'_feedname'))
	Configuration::updateValue($this->name.'_feedname', 'Gase Center Calau');
	if (!Configuration::get($this->name.'_feedcategory'))
	Configuration::updateValue($this->name.'_feedcategory', 'Bauteile &gt; Werkzeuge &gt; Toolsets &gt; Elektrowerkzeug-Combo-Sets');
	if (!Configuration::get($this->name.'_description'))
	Configuration::updateValue($this->name.'_description', '****Type some text to describe your shop before generating your first feed****');
	if (!Configuration::get($this->name.'_lang'))
	Configuration::updateValue($this->name.'_lang', $this->_cookie->id_lang);
	if (!Configuration::get($this->name.'_gtin'))
	Configuration::updateValue($this->name.'_gtin', 'ean13');
	if (!Configuration::get($this->name.'_use_supplier'))
  	Configuration::updateValue($this->name.'_use_supplier', 1);
	if (!Configuration::get($this->name.'_currency'))
	Configuration::updateValue($this->name.'_currency', (int)Configuration::get('PS_CURRENCY_DEFAULT'));
	if (!Configuration::get($this->name.'_condition'))
	Configuration::updateValue($this->name.'_condition', 'new');
	$this->_getGlobals();
	if (!Configuration::get($this->name.'_filepath'))
	Configuration::updateValue($this->name.'_filepath', addslashes($this->defaultOutputFile()));
	$this->_nearby = false;
  }

  private function _getGlobals()
  {
    $this->xml_description = Configuration::get($this->name.'_description');
    $this->psdir = __PS_BASE_URI__;

    $this->languages = $this->getLanguages();
    $this->id_lang = intval(Configuration::get($this->name.'_lang'));
    $this->lang_iso = Tools::strtolower(Language::getIsoById($this->id_lang));
    if (!isset($this->languages[$this->id_lang]))
    {
      Configuration::updateValue($this->name.'_lang', (int)$this->_cookie->id_lang);
      $this->id_lang = (int)$this->_cookie->id_lang;
      $this->lang_iso = Tools::strtolower(Language::getIsoById($this->id_lang));
      $this->warnings[] = $this->l('Language configuration is invalid - reset to default.');
    }

    $this->gtin_field = Configuration::get($this->name.'_gtin');

    $this->use_supplier = Configuration::get($this->name.'_use_supplier');

    $this->currencies = $this->getCurrencies();
    $this->id_currency = intval(Configuration::get($this->name.'_currency'));
    if (!isset($this->currencies[$this->id_currency]))
    {
      Configuration::updateValue($this->name.'_currency', (int)Configuration::get('PS_CURRENCY_DEFAULT'));
      $this->id_currency = (int)Configuration::get('PS_CURRENCY_DEFAULT');
      $this->warnings[] = $this->l('Currency configuration is invalid - reset to default.');
    }

    $this->default_condition = Configuration::get($this->name.'_condition');
  }

	private function directory()
	{
		return dirname(__FILE__).'/../../'; // move up to the __PS_BASE_URI__ directory
	}


	private function winFixFilename($file)
	{
		return str_replace('\\\\','\\',$file);
	}

	private function defaultOutputFile()
	{
		// PHP on windows seems to return a trailing '\' where as on unix it doesn't
		$output_dir = realpath($this->directory());
		$dir_separator = '/';

		// If there's a windows directory separator on the end,
		// then don't add the unix one too when building the final output file
		if (Tools::substr($output_dir, -1, 1)=='\\')
			$dir_separator = '';

		$output_file = $output_dir.$dir_separator.$this->lang_iso.'_'.Tools::strtolower($this->currencies[$this->id_currency]->iso_code).'_googlebase.xml';
		return $output_file;
	}


  static private $cacheCat = array();
  private function _getrawCatRewrite($id_cat)
  {
   if (!isset(self::$cacheCat[$id_cat]))
    {
       $row = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow("
        SELECT `link_rewrite`
        FROM `"._DB_PREFIX_."category_lang`
        WHERE `id_category` = '".(int)($id_cat)."' AND
        `id_lang` = '".(int)$this->id_lang."'");

       if ($row)
       {
        self::$cacheCat[$id_cat] = $row['link_rewrite'];
        return self::$cacheCat[$id_cat];
       }
       else
       {
        self::$cacheCat[$id_cat] = '';
        $this->errors[] = $this->l('Error processing category with id='.$id_cat);
       }
    }
    return self::$cacheCat[$id_cat];
  }

  static private $cacheCatPath = array();
  private function getPath($id_cat)
	{
    if (!isset(self::$cacheCatPath[$id_cat]))
      self::$cacheCatPath[$id_cat] = $this->_getPath($id_cat);

    return self::$cacheCatPath[$id_cat];
  }

  private function _getPath($id_category, $path = '')
  {
    $category = new Category(intval($id_category), intval(Configuration::get($this->name.'_lang')));

		if (!Validate::isLoadedObject($category))
			die (Tools::displayError());

		if ($category->id == 1)
			return $this->_xmlentities($path);

		$pipe = ' > ';
    $path='';
		if ($path != @$category_name)
			$path = $category_name.($path!='' ? $pipe.$path : '');

		return $this->_getPath(intval($category->id_parent), $path);
	}

	private function file_url()
	{
		$filename = $this->winFixFilename(Configuration::get($this->name.'_filepath'));
		$root_path = realpath($this->directory());
		$file = str_replace($root_path,'', $filename);

		$separator = '';

		if (Tools::substr($file, 0, 1)=='\\')
			Tools::substr_replace($file, '/', 0, 1);

		if (Tools::substr($file, 0, 1)!='/')
			$separator = '/';

		return 'http://'.$_SERVER['HTTP_HOST'].$separator.$file;
	}

	private function _addToFeed($str)
	{
		$filename = $this->winFixFilename(Configuration::get($this->name.'_filepath'));
		if(file_exists($filename))
		{
			$fp = fopen($filename, 'ab');
			fwrite($fp, $str, Tools::strlen($str));
			fclose($fp);
		}
	}

	private function _postProcess()
	{
		$products = Product::getProducts($this->id_lang, 0, NULL, 'id_product', 'DESC');

		if($products)
		{
      if (!$fp = fopen($this->winFixFilename(Configuration::get($this->name.'_filepath')), 'w'))
      {
        $this->_mod_errors[] = $this->l('Error writing to feed file.');
        return;
      }
      fclose($fp);

      // Required headers
      $items = "<?xml version=\"1.0\"?>\n\n"
			. "<rss version =\"2.0\" xmlns:g=\"http://base.google.com/ns/1.0\">\n\n"
            . "<channel>\n"
	        . "<title>".Tools::getValue('feedname', Configuration::get($this->name.'_feedname'))."</title>\n"
          . "<link>http://".$_SERVER['HTTP_HOST']."/</link>\n"
	        . "<description>".$this->_xmlentities($this->xml_description)."</description>\n"
			. "\n";

			foreach ($products AS $product)
			{
			  if ($product['active']) {
          $items .= "<item>\n";
          $items .= $this->_processProduct($product);
          $items .= "</item>\n\n";
			  }
			}
			$this->_addToFeed( "$items</channel>\n</rss>\n" );
		}

		$res = file_exists($this->winFixFilename(Configuration::get($this->name.'_filepath')));
		if ($res)
      $this->_html .= '<div class="bootstrap">
		<div class="alert alert-success">
			<button type="button" class="close" data-dismiss="alert">×</button>
			'.$this->l('Feed erfolgreich generiert').' --> <a href="'.$this->file_url().'" target="_blank">'.$this->file_url().'</a>
		</div>
	</div>';
    else
       $this->_mod_errors[] = $this->l('Error while creating feed file');
	}

  private function _xmlentities($string)
  {
    $string = str_replace('&', '&amp;', $string);
    $string = str_replace('"', '&quot;', $string);
    $string = str_replace('\'', '&apos;', $string);
    $string = str_replace('`', '&apos;', $string);
    $string = str_replace('<', '&lt;', $string);
    $string = str_replace('>', '&gt;', $string);

    return ($string);
  }

  private function _xmlElement($name, $value, $encoding = false)
  {
    $element = '';
    if (!empty($value))
    {
      if ($encoding)
        $value = $this->_xmlentities($value);
      $element .= "<".$name.">".$value."</".$name.">\n";
    }
    return $element;
  }

  private function _processProduct($product)
  {
    $item_data = '';
    $product_link = $this->_getCompatibleProductLink($product);
    $image_links = $this->_getCompatibleImageLinks($product);

    // Reference page: http://www.google.com/support/merchants/bin/answer.py?answer=188494

    // 1. Basic Product Information

    $item_data .= $this->_xmlElement('g:id',"pc".$this->lang_iso."-".$product['id_product']);
    $item_data .= $this->_xmlElement('title',$product['name'], true);
    $item_data .= $this->_xmlElement('description','<![CDATA['.$product['description_short'].']]>');
    $item_data .= $this->_xmlElement('g:google_product_category',Tools::getValue('feedcategory', Configuration::get($this->name.'_feedcategory')));
    $item_data .= $this->_xmlElement('g:product_type',$this->getPath($product['id_category_default']));
    $item_data .= $this->_xmlElement('g:shipping', '<g:country>DE</g:country>
   <g:service>Standard</g:service>
   <g:price>4.00 EUR</g:price>');
  
   $item_data .= $this->_xmlElement('g:adwords_redirect', $product_link);

    
    $item_data .= $this->_xmlElement('link',$product_link);
    if ($image_links[0]['valid'] == 1)
      $item_data .= $this->_xmlElement('g:image_link',$image_links[0]['link']);
    if ($image_links[1]['valid'] == 1)
      $item_data .= $this->_xmlElement('g:additional_image_link',$image_links[1]['link']);
    if ((int)@$this->compat > 13)
      $item_data .= $this->_xmlElement('g:condition', $this->_getCompatibleCondition($product['condition']));
    else
      $item_data .= $this->_xmlElement('g:condition',$this->_getCompatibleCondition($this->default_condition));

    // 2. Availability & Price
    $item_data .= $this->_xmlElement('g:availability',$this->getAvailability($product['quantity']));
    // Price is WITHOUT any reduction
    $price = $this->_getCompatiblePrice($product['id_product']);
    $item_data .= $this->_xmlElement('g:price', $price);
    // TODO: If there is an active discount, then include it
    $price_with_reduction = $this->_getCompatibleSalePrice($product['id_product']);
    if ($price_with_reduction !== $price)
      $item_data .= $this->_xmlElement('g:sale_price',$price_with_reduction);
    /*
    // Effective date is in ISO8601 format TODO: Support "sales" somehow - need a way of returning "expiry date" for the reduction
    $items .= "<g:sale_price_effective_date>".Product::getPriceStatic(intval($product['id_product']))."</g:sale_price_effective_date>\n";
    */

    // 3. Unique Product Identifiers
    if ($product['manufacturer_name'])
      $item_data .= $this->_xmlElement('g:brand',$product['manufacturer_name'], true);
    if ($this->gtin_field == 'ean13')
      $item_data .= $this->_xmlElement('g:gtin', $product['ean13']);
    else if ($this->gtin_field == 'upc')
      $item_data .= $this->_xmlElement('g:gtin',sprintf('%1$012d',$product['upc']));
    if ($this->use_supplier)
      $item_data .= $this->_xmlElement('g:mpn',$product['supplier_reference']);

    // 7. Nearby Stores (US & UK only)
    if ($this->nearby and $this->_compat > 13)
      $item_data .= $this->_xmlElement('g:online_only',$product['online_only'] == 1 ? 'y' : 'n');

    return $item_data;
  }

  private function _getCompatibleCondition($condition)
  {
    switch ($condition)
    {
      case 'new':
        $condition = $this->l('new');
      break;
      case 'used':
        $condition = $this->l('used');
      break;
      case 'refurbished':
        $condition = $this->l('refurbished');
      break;
    }
	return $condition;
  }

  private function _getCompatiblePrice($id_product, $id_product_attrib = NULL)
  {
    $price = number_format(Tools::convertPrice(Product::getPriceStatic(intval($id_product), true, $id_product_attrib, 6, NULL, false, false), $this->currencies[$this->id_currency]), 2, '.', '');

    return $price.' '.$this->currencies[$this->id_currency]->iso_code;
  }

  private function _getCompatibleSalePrice($id_product, $id_product_attrib = NULL)
  {
    $price = number_format(Tools::convertPrice(Product::getPriceStatic(intval($id_product), true, $id_product_attrib, 6), $this->currencies[$this->id_currency]), 2, '.', '');

    return $price.' '.$this->currencies[$this->id_currency]->iso_code;
  }

  private function _getCompatibleImageLinks($product)
  {
    $link = new Link();
    $image_data = array(array('link' => '', 'valid' => 0), array('link' => '', 'valid' => 0));
    $images = Image::getImages($this->id_lang, $product['id_product']);

    switch ($this->_compat)
    {
      case '11':
        if (isset($images[0]))
        {

          $image_data[0]['link'] = $this->psdir.'img/p/'.$images[0]['id_product'].'-'.$images[0]['id_image'].'-large.jpg';
          $image_data[0]['valid'] = 1;
        }
        if (isset($images[1]))
        {
					$image_data[1]['link'] = 'http://'.$_SERVER['HTTP_HOST'].$this->psdir.'img/p/'.$images[1]['id_product'].'-'.$images[1]['id_image'].'-large.jpg';
          $image_data[1]['valid'] = 1;
        }
      break;

      case '14':
        if (isset($images[0]))
        {

          $image_data[0]['link'] = $link->getImageLink($product['link_rewrite'], (int)$product['id_product'].'-'.(int)$images[0]['id_image']);
          $image_data[0]['valid'] = 1;
        }
        if (isset($images[1]))
        {
					$image_data[1]['link'] = $link->getImageLink($product['link_rewrite'], (int)$product['id_product'].'-'.(int)$images[1]['id_image']);;
          $image_data[1]['valid'] = 1;
        }
      break;

      default:
        if (isset($images[0]))
        {

          $image_data[0]['link'] = 'http://'.$link->getImageLink($product['link_rewrite'], (int)$product['id_product'].'-'.(int)$images[0]['id_image']);
          $image_data[0]['valid'] = 1;
        }
        if (isset($images[1]))
        {
					$image_data[1]['link'] = 'http://'.$_SERVER['HTTP_HOST'].$this->psdir.$link->getImageLink($product['link_rewrite'], (int)$product['id_product'].'-'.(int)$images[1]['id_image']);;
          $image_data[1]['valid'] = 1;
        }
      break;

    }
    return $image_data;
  }

  private function _getCompatibleProductLink($product)
  {
    $link = new Link();
    switch ($this->_compat)
    {
      case '11':
      $product_link = $link->getProductLink($product['id_product'], $product['link_rewrite']);
      // Make 1.1 result look like 1.2+
      if (strpos( $product_link, 'http://' ) === false )
        $product_link = 'http://'.$_SERVER['HTTP_HOST'].$product_link;
      break;

      case '12':
        $product_link = $link->getProductLink((int)($product['id_product']), $product['link_rewrite'], $this->_getrawCatRewrite($product['id_category_default']), $product['ean13']);
      break;

      default:
        $product_link = $link->getProductLink((int)($product['id_product']), $product['link_rewrite'], $this->_getrawCatRewrite($product['id_category_default']), $product['ean13'], (int)$this->id_lang);
      break;
    }

    return $product_link;
  }

  private function _displayFeed()
	{
		$filename = $this->winFixFilename(Configuration::get($this->name.'_filepath'));
		if(file_exists($filename))
		{
			$this->_html .= '
      <fieldset style="width:70%"><legend><img src="../img/admin/enabled.gif" alt="" class="middle" />'.$this->l('Feed Generated').'</legend>';
			if (strpos($filename,realpath($this->directory())) === FALSE)
			{
        $this->_html .= '<p>'.$this->l('Your Google Base feed file is available via ftp as the following:').' <b>'.$filename.'</b></p><br />';
			} else {
				$this->_html .= '<p>'.$this->l('Your Google Base feed file is online at the following address:').' <a href="'.$this->file_url().'"><b>'.$this->file_url().'</b></a></p><br />';
			}
			$this->_html .= $this->l('Last Updated:').' <b>'.date('m.d.y G:i:s', filemtime($filename)).'</b><br />';
			$this->_html .= '</fieldset>';
      	$this->_html .= '<div style="width:70%"><center><h2>&copy2015 www.prestacode.de</h2></center></div>';
      
		} else {
			$this->_html .= '<fieldset><legend><img src="../img/admin/delete.gif" alt="" class="middle" />'.$this->l('No Feed Generated').'</legend>';
			$this->_html .= '<br /><h3 class="alert error" style="margin-bottom: 20px">No feed file has been generated at this location yet!</h3>';
			$this->_html .= '</fieldset>';
		}
	}

	private function _displayForm()
	{
		$this->use_supplier = (int)(Tools::isSubmit('use_supplier') ? 1 : Configuration::get($this->name.'_use_supplier'));
    $this->gtin_field = Tools::getValue('gtin', Configuration::get($this->name.'_gtin'));
    $this->currency = Tools::getValue('currency', Configuration::get($this->name.'_currency'));
    $this->id_lang = Tools::getValue('language', Configuration::get($this->name.'_lang'));

    $this->_html .=
			'<form action="'.$_SERVER['REQUEST_URI'].'" method="post">
        <input name="btnSubmit" id="btnSubmit" class="button" value="'.$this->l('XML Feed Generieren').'" type="submit" />
      </form>'.
      '<form action="'.$_SERVER['REQUEST_URI'].'" method="post">
				<br />
				<fieldset style="width:70%">
					<legend><img src="../img/admin/cog.gif" alt="" class="middle" />'.$this->l('Settings').'</legend>
					<fieldset class="space">
						<p style="font-size: smaller;"><img src="../img/admin/unknown.gif" alt="" class="middle" />'.
            $this->l('localisation').'</p>
					</fieldset>
					<br />
          <label>'.$this->l('Currency').'</label>
          <div class="margin-form">
            <select name="currency" id="currency" >';
            foreach ($this->currencies as $id => $currency)
            {
              if ($id)
                $this->_html .= '<option value="'.$id.'"'.($this->currency == $id ? ' selected="selected"' : '').' > '.$currency->iso_code.' </option>';
            }
            $this->_html .='</select>
            <p class="clear">'.$this->l('Store default ='). ' ' . $this->currencies[(int)Configuration::get('PS_CURRENCY_DEFAULT')]->iso_code.'</p>
          </div>
          <label>'.$this->l('Language').'</label>
          <div class="margin-form">
            <select name="language" id="language" >';
            foreach ($this->languages as $language)
            {
                $this->_html .= '<option value="'.$language['id_lang'].'"'.($this->id_lang == $language['id_lang'] ? ' selected="selected"' : '').' > '.$language['name'].' </option>';
            }
            $this->_html .='</select>
            <p class="clear">'.$this->l('Store default ='). ' ' . $this->languages[$this->_cookie->id_lang]['name'].'</p>
          </div>
          <fieldset class="space">
						<p style="font-size: smaller;"><img src="../img/admin/unknown.gif" alt="" class="middle" />'.
            $this->l('required').'</p>
					</fieldset>
					<br />
					<label>'.$this->l('Feed Name: ').'</label>
					<div class="margin-form">
						<input name="feedname" type="text" size="25" value="'.Tools::getValue('feedname', Configuration::get($this->name.'_feedname')).'" />
						<p class="clear">'.$this->l('Example:').' '.$this->l('Feedname_example').'</p></div>
            <label>'.$this->l('Feed Kategorie: ').'</label>
					<div class="margin-form">
						<input name="feedcategory" type="text" size="50"value="'.Tools::getValue('feedcategory', Configuration::get($this->name.'_feedcategory')).'" />
						<p class="clear">'.$this->l('Example:').''.$this->l('Feedcat_example').' Download:<a href="http://www.google.com/basepages/producttype/taxonomy.de-DE.txt" target="_blank">http://www.google.com/basepages/producttype/taxonomy.de-DE.txt</a></p>
					</div>
          <label>'.$this->l('Feed Description: ').'</label>
					<div class="margin-form">
						<textarea name="description" rows="5" cols="80" >'.Tools::getValue('description', Configuration::get($this->name.'_description')).'</textarea>
						<p class="clear">'.$this->l('Example:').''.$this->l('Feeddesc_example').'</p>
					</div>
					<label>'.$this->l('Output Location: ').'</label>
					<div class="margin-form">
						<input name="filepath" type="text" style="width: 600px;" value="'.(isset(Tools::getValue(filepath)) ? Tools::getValue(filepath) : $this->winFixFilename(Configuration::get($this->name.'_filepath'))).'"/>
						<p class="clear">'.$this->l('Recommended path:').' '.$this->defaultOutputFile().'</p>
					</div>
          <fieldset class="space">
						<p style="font-size: smaller;"><img src="../img/admin/unknown.gif" alt="" class="middle" />'.
            $this->l('mandatory_settings').'</p>
					</fieldset>
					<br />
          <label>'.$this->l('Use Supplier Reference').'</label>
          <div class="margin-form">
            <input type="checkbox" name="use_supplier" id="use_supplier" value="1"' . ($this->use_supplier ? 'checked="checked" ' : '') . ' />
            <p class="clear">'.$this->l('Use the supplier reference field as Manufacturers Part Number (MPN)').' Hilfe: <a href="https://support.google.com/merchants/answer/160161?hl=de" target="_blank">Eindeutige Profuktkennzeichnungen - Google</a></p>
          </div>
          <label>'.$this->l('Unique Product Identifier').'</label>
          <div class="margin-form">
            <input type="radio" name="gtin" id="gtin_0" value="ean13" '.($this->gtin_field == 'ean13' ? 'checked="checked" ' : '').' > EAN13</option>
            <input type="radio" name="gtin" id="gtin_1" value="upc" '.($this->gtin_field == 'upc' ? 'checked="checked" ' : '').' > UPC</option>
            <input type="radio" name="gtin" id="gtin_2" value="none" '.($this->gtin_field == 'none' ? 'checked="checked" ' : '').' > None</option>
            <p class="clear">'.$this->l('Mandatory unless you specify the Manufacturer and MPN (see above). Either: EAN13 (EU) or UPC (US)').'</p>
          </div>
          <input name="btnUpdate" id="btnUpdate" class="button" value="'.((!file_exists($this->winFixFilename(Configuration::get($this->name.'_filepath')))) ? $this->l('Update Settings') : $this->l('Update Settings')).'" type="submit" />
          <form action="'.$_SERVER['REQUEST_URI'].'" method="post">
        <input name="btnSubmit" id="btnSubmit" class="button" value="'.$this->l('XML Feed Generieren').'" type="submit" />
      </form>
				</fieldset>
			</form><br/>';
	}

	private function _postValidation()
	{
		// TODO Need to review form validation.....
    // Used $_POST here to allow us to modify them directly - naughty I know :)

		if (empty(Tools::getValue(description)) OR Tools::strlen(Tools::getValue(description)) > 10000)
			$this->_mod_errors[] = $this->l('Description is invalid');
		// could check that this is a valid path, but the next test will
		// do that for us anyway
		// But first we need to get rid of the escape characters
		Tools::getValue(filepath) = $this->winFixFilename(Tools::getValue(filepath));
		if (empty(Tools::getValue(filepath)) OR (Tools::strlen(Tools::getValue(filepath)) > 255))
			$this->_mod_errors[] = $this->l('The target location is invalid');

		if (file_exists(Tools::getValue('filepath')) && !is_writable(Tools::getValue('filepath')))
			$this->_mod_errors[] = $this->l('File error.<br />Cannot write to').' '.Tools::getValue('filepath');
	}

	public function getContent()
	{
		$this->_html .= '<h2>'.$this->l('Google Shopping Produkt Feed').'</h2>';
    if(!is_writable(realpath($this->directory())))
			$this->_warnings[] = $this->l('Output directory must be writable or the feed file will need to be pre-created with write permissions.');

    if(isset($this->_warnings) AND sizeof($this->_warnings))
    {
      $this->_displayWarnings($this->_warnings);
    }

		if (Tools::getValue('btnUpdate'))
		{
			$this->_postValidation();

			if (!sizeof($this->_mod_errors))
			{
				Configuration::updateValue($this->name.'_feedcategory', Tools::getValue('feedcategory'));
        Configuration::updateValue($this->name.'_feedname', Tools::getValue('feedname'));
        Configuration::updateValue($this->name.'_description', Tools::getValue('description'));
				Configuration::updateValue($this->name.'_filepath', addslashes(Tools::getValue('filepath'))); // the Tools class kills the windows file name separators :(
        Configuration::updateValue($this->name.'_gtin', Tools::getValue('gtin'));	// gtin field selection
        Configuration::updateValue($this->name.'_use_supplier', (int)(Tools::isSubmit('use_supplier')));
        Configuration::updateValue($this->name.'_currency', (int)Tools::getValue('currency')); // Feed currency
        Configuration::updateValue($this->name.'_lang', (int)Tools::getValue('language'));	// language to generate feed for

        $this->_getGlobals();
			}
			else
			{
       if(isset($this->_mod_errors) AND sizeof($this->_mod_errors))
        {
          $this->_displayErrors($this->_mod_errors);
        }
			}
		} else if (Tools::getValue('btnSubmit')) {
          // Go try and generate the feed
          $this->_postProcess();
    }

		$this->_displayForm();
    $this->_displayFeed();

		return $this->_html;
	}

	public function	_displayWarnings($warn)
	{
		$str_output = '';
		if (!empty($warn))
		{
			$str_output .= '<script type="text/javascript">
					$(document).ready(function() {
						$(\'#linkSeeMore\').unbind(\'click\').click(function(){
							$(\'#seeMore\').show(\'slow\');
							$(this).hide();
							$(\'#linkHide\').show();
							return false;
						});
						$(\'#linkHide\').unbind(\'click\').click(function(){
							$(\'#seeMore\').hide(\'slow\');
							$(this).hide();
							$(\'#linkSeeMore\').show();
							return false;
						});
						$(\'#hideWarn\').unbind(\'click\').click(function(){
							$(\'.warn\').hide(\'slow\', function (){
								$(\'.warn\').remove();
							});
							return false;
						});
					});
				  </script>
			<div class="warn">';
			if (!is_array($warn))
				$str_output .= '<img src="../img/admin/warn2.png" />'.$warn;
			else
			{	$str_output .= '<span style="float:right"><a id="hideWarn" href=""><img alt="X" src="../img/admin/close.png" /></a></span><img src="../img/admin/warn2.png" />'.
				(count($warn) > 1 ? $this->l('There are') : $this->l('There is')).' '.count($warn).' '.(count($warn) > 1 ? $this->l('warnings') : $this->l('warning'))
				.'<span style="margin-left:20px;" id="labelSeeMore">
				<a id="linkSeeMore" href="#" style="text-decoration:underline">'.$this->l('Click here to see more').'</a>
				<a id="linkHide" href="#" style="text-decoration:underline;display:none">'.$this->l('Hide warning').'</a></span><ul style="display:none;" id="seeMore">';
				foreach($warn as $val)
					$str_output .= '<li>'.$val.'</li>';
				$str_output .= '</ul>';
			}
			$str_output .= '</div>';
		}
		echo $str_output;
	}

	/**
	 * Display errors
	 */
	public function _displayErrors()
	{
		if ($nbErrors = count($this->_mod_errors))
		{
			echo '<script type="text/javascript">
				$(document).ready(function() {
					$(\'#hideError\').unbind(\'click\').click(function(){
						$(\'.error\').hide(\'slow\', function (){
							$(\'.error\').remove();
						});
						return false;
					});
				});
			  </script>
			<div class="error"><span style="float:right"><a id="hideError" href=""><img alt="X" src="../img/admin/close.png" /></a></span><img src="../img/admin/error2.png" />';
			if (count($this->_mod_errors) == 1)
				echo $this->_mod_errors[0];
			else
			{
				echo $nbErrors.' '.$this->l('errors').'<br /><ol>';
				foreach ($this->_mod_errors AS $error)
					echo '<li>'.$error.'</li>';
				echo '</ol>';
			}
			echo '</div>';
		}
	}

  public function getAvailability($quantity)
  {
    if ($quantity > 0)
      return $this->l('in stock');
    else if ($quantity = 0 && !Configuration::get('PS_STOCK_MANAGEMENT'))
      return $this->l('available for order');
    else
      return $this->l('in stock');
  }

	public function getCurrencies($object = true, $active = 1)
	{
		switch ($this->_compat)
    {
      case '14':
        $tab = Db::getInstance()->ExecuteS('
        SELECT *
        FROM `'._DB_PREFIX_.'currency`
        WHERE `deleted` = 0
        '.($active == 1 ? 'AND `active` = 1' : '').'
        ORDER BY `name` ASC');
      break;
      default:
        $tab = Db::getInstance()->ExecuteS('
        SELECT *
        FROM `'._DB_PREFIX_.'currency`
        WHERE `deleted` = 0
        ORDER BY `name` ASC');
      break;
    }

    if ($object)
    $key = '';
			foreach ($tab as $key => $currency)
				$tab[$currency['id_currency']] = Currency::getCurrencyInstance($currency['id_currency']);
		return $tab;
	}

  public function getLanguages()
	{
    $languages = array();

    $result = Db::getInstance()->ExecuteS("
		SELECT `id_lang`, `name`, `iso_code`
		FROM `"._DB_PREFIX_."lang` WHERE `active` = '1'");

		foreach ($result AS $row)
			$languages[(int)($row['id_lang'])] = array('id_lang' => (int)($row['id_lang']), 'name' => $row['name'], 'iso_code' => $row['iso_code'], 'active' => (int)(@$row['active']));
    return $languages;
  }
}
?>

