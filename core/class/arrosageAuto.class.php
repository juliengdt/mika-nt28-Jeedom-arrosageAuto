<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
class arrosageAuto extends eqLogic {	
	/*public static function deamon_info() {
		$return = array();
		$return['log'] = 'arrosageAuto';
		$return['launchable'] = 'ok';
		$return['state'] = 'ok';
		foreach(eqLogic::byType('arrosageAuto') as $zone){
			if($zone->getIsEnable() && $zone->getCmd(null,'isArmed')->execCmd()){
				$cron = cron::byClassAndFunction('arrosageAuto', 'Arrosage',array('id' => $zone->getId()));
				if (!is_object($cron)) 	{	
					$return['state'] = 'nok';
					return $return;
				}
			}
		}
		return $return;
	}
	public static function deamon_start($_debug = false) {
		log::remove('arrosageAuto');
		self::deamon_stop();
		$deamon_info = self::deamon_info();
		if ($deamon_info['launchable'] != 'ok') 
			return;
		if ($deamon_info['state'] == 'ok') 
			return;
		foreach(eqLogic::byType('arrosageAuto') as $zone){
			if($zone->getIsEnable() && $zone->getCmd(null,'isArmed')->execCmd()){
				$nextTime=$zone->NextProg();
				$zone->CreateCron(date('i H d m w Y',$nextTime));
			}
		}
	}
	public static function deamon_stop() {	
		foreach(eqLogic::byType('arrosageAuto') as $zone){
			$cron = cron::byClassAndFunction('arrosageAuto', 'Arrosage',array('id' => $zone->getId()));
			if (is_object($cron)) 	
				$cron->remove();
		}
	}*/
	public static function cron() {	
		$NextProg=self::NextProg();
		$DebitArroseurs=0;
		$TempsArroseurs=0;
		foreach(eqLogic::byType('arrosageAuto') as $zone){
			if($zone->getIsEnable() && $zone->getCmd(null,'isArmed')->execCmd()){
				log::add('arrosageAuto','info',$zone->getHumanName().' : La zone est desactivée');
				continue;
			}
			$DebitArroseurs+=$zone->CheckDebit();
			if(!self::CheckPompe($DebitArroseurs)){
				$DebitArroseurs=0;
				$TempsArroseurs+=$zone->EvaluateTime(jeedom::evaluateExpression(config::byKey('cmdPrecipitation','arrosageAuto')));	
			}
			$zone->CreateCron(date('i H d m w Y',$NextProg+$TempsArroseurs));
			$zone->refreshWidget();
		}
	}
	public static function Arrosage($_option){
		$zone=eqLogic::byId($_option['id']);
		if(is_object($zone)){			
			if(!$zone->getCmd(null,'isArmed')->execCmd()){
				log::add('arrosageAuto','info',$zone->getHumanName().' : La zone est desactivée');
				exit;
			}
			if(!$zone->CheckCondition()){
				log::add('arrosageAuto','info',$zone->getHumanName().' : Les conditions ne sont pas evaluées');
				exit;
			}
			if($plui=$zone->CheckMeteo() === false){
				log::add('arrosageAuto','info',$zone->getHumanName().' : La météo n\'est pas idéale pour l\'arrosage');
				exit;
			}
			$zone->ExecuteAction('start');
			$PowerTime=$zone->EvaluateTime($plui);
			log::add('arrosageAuto','info',$zone->getHumanName().' : Estimation du temps d\'activation '.$PowerTime.'s');
			sleep($PowerTime);
			$zone->ExecuteAction('stop');
		}
	}
	public static function NextProg(){
		$nextTime=null;
		foreach(config::byKey('Programmations', 'arrosageAuto') as $ConigSchedule){
			$offset=0;
			if(date('H') > $ConigSchedule["Heure"])
				$offset++;
			if(date('H') == $ConigSchedule["Heure"] && date('i') >= $ConigSchedule["Minute"])	
				$offset++;
			for($day=0;$day<7;$day++){
				if($ConigSchedule[date('w')+$day+$offset]){
					$offset+=$day;
					$timestamp=mktime ($ConigSchedule["Heure"], $ConigSchedule["Minute"], 0, date("n") , date("j") , date("Y"))+ (3600 * 24) * $offset;
					break;
				}
			}
			if($nextTime == null || $nextTime > $timestamp)
				$nextTime = $timestamp;
		}
		return $nextTime;
	}
	public function CreateCron($Schedule) {
		log::add('arrosageAuto','debug',$this->getHumanName().' : Création du cron  ID --> '.$Schedule);
		$cron = cron::byClassAndFunction('arrosageAuto', "Arrosage" ,array('id' => $this->getId()));
		if (!is_object($cron)) 
			$cron = new cron();
		$cron->setClass('arrosageAuto');
		$cron->setFunction("Arrosage");
		$options['id']= $this->getId();
		$cron->setOption($options);
		$cron->setEnable(1);
		$cron->setSchedule($Schedule);
		$cron->save();
		return $cron;
	}
	public static function CheckPompe($DebitArroseurs){
		$DebitPmp=config::byKey('debit','arrosageAuto');
		if($DebitPmp<$DebitArroseurs)
			return false;
		return true;
	}
	public function CheckDebit(){
		$Debit=0;
		foreach($this->getConfiguration('arroseur') as $Arroseur){
			$Debit += $Arroseur['Debit'];
		}
		return $Debit; 
	}
	public function ExecuteArrosage(){
		$this->ExecuteAction('start');
		$PowerTime=$this->EvaluateTime($plui);
		log::add('arrosageAuto','info',$this->getHumanName().' : Estimation du temps d\'activation '.$PowerTime.'s');
		sleep($PowerTime);
		$this->ExecuteAction('stop');
	}
	public function toHtml($_version = 'dashboard') {
		$replace = $this->preToHtml($_version);
		if (!is_array($replace)) 
			return $replace;
		$version = jeedom::versionAlias($_version);
		if ($this->getDisplay('hideOn' . $version) == 1)
			return '';
		foreach ($this->getCmd() as $cmd) {
			if ($cmd->getDisplay('hideOn' . $version) == 1)
				continue;
			$replace['#'.$cmd->getLogicalId().'#']= $cmd->toHtml($_version, $cmdColor);
		}
		$replace['#cmdColor#'] = ($this->getPrimaryCategory() == '') ? '' : jeedom::getConfiguration('eqLogic:category:' . $this->getPrimaryCategory() . ':' . $vcolor);
		$cron = cron::byClassAndFunction('arrosageAuto', 'Arrosage',array('id' => $this->getId()));
		$replace['#NextStart#'] = ' - ';
		if(is_object($cron))
			$replace['#NextStart#'] = $cron->getNextRunDate();
		if($plui=$this->CheckMeteo() === false)		
			$replace['#NextStop#'] = 'Météo incompatible';
		else{
			$PowerTime=$this->EvaluateTime($plui);	
			$replace['#NextStop#'] = $PowerTime;//date('d/m/Y H:i',$NextProg+$PowerTime);
		}
		if ($_version == 'dview' || $_version == 'mview') {
			$object = $this->getObject();
			$replace['#name#'] = (is_object($object)) ? $object->getName() . ' - ' . $replace['#name#'] : $replace['#name#'];
		}
      		return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'eqLogic', 'arrosageAuto')));
  	}
	public static $_widgetPossibility = array('custom' => array(
	        'visibility' => true,
	        'displayName' => true,
	        'displayObjectName' => true,
	        'optionalParameters' => true,
	        'background-color' => true,
	        'text-color' => true,
	        'border' => true,
	        'border-radius' => true
	));
	public function postSave() {
		$isArmed=$this->AddCommande("État activation","isArmed","info","binary",false,'lock');
		$isArmed->event(true);
		$isArmed->setCollectDate(date('Y-m-d H:i:s'));
		$isArmed->save();
		$Armed=$this->AddCommande("Activer","armed","action","other",true,'lock');
		$Armed->setValue($isArmed->getId());
		$Armed->setConfiguration('state', '1');
		$Armed->setConfiguration('armed', '1');
		$Armed->save();
		$Released=$this->AddCommande("Désactiver","released","action","other",true,'lock');
		$Released->setValue($isArmed->getId());
		$Released->setConfiguration('state', '0');
		$Released->setConfiguration('armed', '1');
		$Released->save();
		$Coef=$this->AddCommande("Coefficient","coefficient","info","numeric",false);
		$regCoefficient=$this->AddCommande("Réglage coefficient","regCoefficient","action","slider",true,'coefArros');
		$regCoefficient->setValue($Coef->getId());
		$regCoefficient->save();
	}
	public function preRemove() {
		$cron = cron::byClassAndFunction('arrosageAuto', 'Arrosage',array('id' => $this->getId()));
		if (is_object($cron)) 	
			$cron->remove();
	}
	public function EvaluateTime($plui=0) {
		$TypeArrosage=config::byKey('configuration','arrosageAuto');
		$key=array_search($this->getConfiguration('TypeArrosage'),$TypeArrosage['type']);
		$QtsEau=$TypeArrosage['volume'][$key];
		//Ajouter la verification du nombre de start dans la journée pour repartir la quantité
		$NbProgramation=0;
		foreach(config::byKey('Programmations', 'arrosageAuto') as $programmation){
			if($programmation[date('w')])
				$NbProgramation++;
		}
		$QtsEau-=$plui;
		$QtsEau=$QtsEau/$NbProgramation;
		$Pluviometrie=$this->CalculPluviometrie();
		if($Pluviometrie == 0)
			return $Pluviometrie;
		log::add('arrosageAuto','info',$this->getHumanName().' : Nous devons arroser '.$QtsEau.' mm/m² avec un pluviometrie de '.$Pluviometrie.'mm/s');
		$Temps=$QtsEau/$Pluviometrie;
		return $this->Ratio($Temps);
	}
	public function Ratio($Value){
		$cmd=$this->getCmd(null, 'coefficient');
		if(!is_object($cmd))
			return $Value;
		$min=$cmd->getConfiguration('minValue');
		$max=$cmd->getConfiguration('maxValue');
		if($min == '' && $max == '')
			return $Value;
		if($min == '')
			$min=0;
		if($max == '')
			$max=300;
		return round(($Value/100)*($max-$min)+$min);
		
	}
	public function ExecuteAction($Type) {
		foreach($this->getConfiguration('action') as $cmd){
			if (isset($cmd['enable']) && $cmd['enable'] == 0)
				continue;
			if ($cmd['Type'] != $Type && $Type !='')
				continue;
			try {
				$options = array();
				if (isset($cmd['options']))
					$options = $cmd['options'];
				scenarioExpression::createAndExec('action', $cmd['cmd'], $options);
			} catch (Exception $e) {
				log::add('arrosageAuto', 'error',$this->getHumanName().' : '. __('Erreur lors de l\'exécution de ', __FILE__) . $action['cmd'] . __('. Détails : ', __FILE__) . $e->getMessage());
			}
			$Commande=cmd::byId(str_replace('#','',$cmd['cmd']));
			if(is_object($Commande)){
				log::add('arrosageAuto','debug',$this->getHumanName().' : Exécution de '.$Commande->getHumanName());
				$Commande->event($cmd['options']);
			}
		}
	}
	public function CheckMeteo(){
		$result=$this->EvaluateCondition(config::byKey('cmdPrecipProbability','arrosageAuto').' < '. config::byKey('precipProbability','arrosageAuto'));
		if(!$result){
			log::add('arrosageAuto','info',$this->getHumanName().' : La probalité de précipitation est trop important');
			return false;
		}
		$result=$this->EvaluateCondition(config::byKey('cmdWindSpeed','arrosageAuto').' < '. config::byKey('windSpeed','arrosageAuto'));
		if(!$result){
			log::add('arrosageAuto','info',$this->getHumanName().' : Il y a trop de vent pour arroser');
			return false;
		}
		$result=$this->EvaluateCondition(config::byKey('cmdHumidity','arrosageAuto').' < '. config::byKey('humidity','arrosageAuto'));
		if(!$result){
			log::add('arrosageAuto','info',$this->getHumanName().' : Il y a suffisament d\'humidié, pas besoin d\'arroser');
			return false;
		}
		$Precipitation= jeedom::evaluateExpression(config::byKey('cmdPrecipitation','arrosageAuto'));
		log::add('arrosageAuto','debug',$this->getHumanName().' : Precipitation '.$Precipitation);
		return $Precipitation;
	}
	public function CheckCondition(){
		foreach($this->getConfiguration('condition') as $Condition){
			if (isset($Condition['enable']) && $Condition['enable'] == 0)
				continue;
			$result=$this->EvaluateCondition($Condition['expression']);
			if(!$result){
				log::add('arrosageAuto','info',$this->getHumanName().' : Les conditions ne sont pas remplies');
				return false;
			}
		}
		log::add('arrosageAuto','info',$this->getHumanName().' : Les conditions sont remplies');
		return true;
	}
	public function boolToText($value){
		if (is_bool($value)) {
			if ($value) 
				return __('Vrai', __FILE__);
			else 
				return __('Faux', __FILE__);
		} else 
			return $value;
	}
	public function EvaluateCondition($Condition){
		$_scenario = null;
		$expression = scenarioExpression::setTags($Condition, $_scenario, true);
		$message = __('Evaluation de la condition : ['.jeedom::toHumanReadable($Condition).'][', __FILE__) . trim($expression) . '] = ';
		$result = evaluate($expression);
		$message .=$this->boolToText($result);
		log::add('arrosageAuto','info',$this->getHumanName().' : '.$message);
		if(!$result)
			return false;		
		return true;
	}
	public function CalculPluviometrie(){
		$Pluviometrie=array();
		foreach($this->getConfiguration('arroseur') as $Arroseur){
			switch($Arroseur['Type']){
				case'gouteAgoute':
					$Debit = 10000 * $Arroseur['Debit'];
					$Espacement = $Arroseur['EspacementLateral'] * $Arroseur['EspacemenGoutteurs'];
					$Pluviometrie[] = $Debit / $Espacement;
				break;
				case'tuyere':
					$Pluviometrie[] = $Arroseur['Debit'] * $Arroseur['Quart'];
				break;
				case'turbine':
					//$Pluviometrie[] = $Arroseur['Debit'] /($Arroseur['Distance'] * $Arroseur['Angle']);
					$Pluviometrie[] = 15;
				break;
			 }
		}
		$Pluviometrie = array_sum($Pluviometrie)/count($Pluviometrie);
		return $Pluviometrie/3600; //Conversion de mm/H en mm/s
	}
	public function AddCommande($Name,$_logicalId,$Type="info", $SubType='binary',$visible,$Template='') {
		$Commande = $this->getCmd(null,$_logicalId);
		if (!is_object($Commande))
		{
			$Commande = new arrosageAutoCmd();
			$Commande->setId(null);
			$Commande->setName($Name);
			$Commande->setIsVisible($visible);
			$Commande->setLogicalId($_logicalId);
			$Commande->setEqLogic_id($this->getId());
			$Commande->setType($Type);
			$Commande->setSubType($SubType);
		}
   		$Commande->setTemplate('dashboard',$Template );
		$Commande->setTemplate('mobile', $Template);
		$Commande->save();
		return $Commande;
	}
}
class arrosageAutoCmd extends cmd {
		public function execute($_options = null) {
		$Listener=cmd::byId(str_replace('#','',$this->getValue()));
		if (is_object($Listener)) {
			switch($this->getLogicalId()){
				case 'armed':
					$Listener->event(true);
				break;
				case 'released':
					$Listener->event(false);
				break;
				case 'regCoefficient':
					$Listener->event($_options['slider']);
				break;
			}
			$Listener->setCollectDate(date('Y-m-d H:i:s'));
			$Listener->save();
		}
	}
}
?>
