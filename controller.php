<?php 
defined('C5_EXECUTE') or die(_("Access Denied."));

class JformPackage extends Package {

	protected $pkgHandle = 'jform';
	protected $appVersionRequired = '5.6.3.1';
	protected $pkgVersion = '1.0.0';

	public function getPackageDescription() {
		return t("Extension for form block.");
	}

	public function getPackageName() {
		return t("Form Bock for Japan");
	}

	public function install() {
		$pkg = parent::install();
	}

	public function uninstall() {
		parent::uninstall();
    }

	public function on_start() {
    	$objEnv = Environment::get();
        $objEnv->overrideCoreByPackage('blocks/form/controller.php', $this);
        $objEnv->overrideCoreByPackage('blocks/form/view.php', $this);
	}

}
