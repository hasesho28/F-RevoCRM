<?php
/* +********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 * ****************************************************************************** */

require_once('data/CRMEntity.php');
require_once('include/utils/utils.php');
require_once 'include/Webservices/Utils.php';

global $adv_filter_options;
global $mod_strings;

$adv_filter_options = array();

if ($mod_strings) {
	$adv_filter_options = array("e" => "" . $mod_strings['equals'] . "",
		"n" => "" . $mod_strings['not equal to'] . "",
		"s" => "" . $mod_strings['starts with'] . "",
		"ew" => "" . $mod_strings['ends with'] . "",
		"c" => "" . $mod_strings['contains'] . "",
		"k" => "" . $mod_strings['does not contain'] . "",
		"l" => "" . $mod_strings['less than'] . "",
		"g" => "" . $mod_strings['greater than'] . "",
		"m" => "" . $mod_strings['less or equal'] . "",
		"h" => "" . $mod_strings['greater or equal'] . "",
		"b" => "" . $mod_strings['before'] . "",
		"a" => "" . $mod_strings['after'] . "",
		"bw" => "" . $mod_strings['between'] . "",
	);
}

class CustomView extends CRMEntity {

	var $module_list = Array();
	var $customviewmodule;
	var $list_fields;
	var $list_fields_name;
	var $setdefaultviewid;
	var $escapemodule;
	var $mandatoryvalues;
	var $showvalues;
	var $data_type;
	// Information as defined for this instance in the database table.
	protected $_status = false;
	protected $_userid = false;
	protected $meta;
	protected $moduleMetaInfo;

	/** This function sets the currentuser id to the class variable smownerid,
	 * modulename to the class variable customviewmodule
	 * @param $module -- The module Name:: Type String(optional)
	 * @returns  nothing
	 */
        function __construct($module = "") {
            global $current_user;
            $this->customviewmodule = $module;
            $this->escapemodule[] = $module . "_";
            $this->escapemodule[] = "_";
            $this->smownerid = $current_user->id;
            $this->moduleMetaInfo = array();
            if ($module != "" && $module != 'Calendar') {
                    $this->meta = $this->getMeta($module, $current_user);
            }
        }   
	function CustomView($module = "") {
            self::__construct($module);
	}

	/**
	 *
	 * @param String:ModuleName $module
	 * @return EntityMeta
	 */
	public function getMeta($module, $user) {
		$db = PearDatabase::getInstance();
		if (empty($this->moduleMetaInfo[$module])) {
			$handler = vtws_getModuleHandlerFromName($module, $user);
			$meta = $handler->getMeta();
			$this->moduleMetaInfo[$module] = $meta;
		}
		return $this->moduleMetaInfo[$module];
	}

	/** To get the customViewId of the specified module
	 * @param $module -- The module Name:: Type String
	 * @returns  customViewId :: Type Integer
	 */
	function getViewId($module) {
		global $adb, $current_user;
		$now_action = vtlib_purify($_REQUEST['action']);
		if(!$now_action) {
			$now_action = vtlib_purify($_REQUEST['view']);
		}
		/*
		表示するリストを決める. 優先度は以下の通り1～4.
		1(前回のセッションで開いていたリスト), 2(デフォルトチェックボックスに✓が入れられたリスト), 3(DBでデフォルトに設定されているリスト), 4(All)
		*/
		if (empty($_REQUEST['viewname'])) {
			if (isset($_SESSION['lvs'][$module]["viewname"]) && $_SESSION['lvs'][$module]["viewname"] != '') {
				$viewid = $_SESSION['lvs'][$module]["viewname"];
			} elseif ($this->setdefaultviewid != "") {
				$viewid = $this->setdefaultviewid;
			} else {
				$defcv_result = $adb->pquery("SELECT ump.default_cvid from vtiger_customview cv left join vtiger_user_module_preferences ump on cv.cvid = ump.default_cvid where ump.userid = ? and ump.tabid = ?", array($current_user->id, getTabid($module)));
				if ($adb->num_rows($defcv_result) > 0) {
					$viewid = $adb->query_result($defcv_result, 0, 'default_cvid');
				} else {
					$viewid = '';
				}
			}

		} else {
			$viewname = vtlib_purify($_REQUEST['viewname']);
			if (!is_numeric($viewname)) {
				if (strtolower($viewname) == 'all' || $viewname == 0) {
					$viewid = $this->getViewIdByName('All', $module);
				} else {
					$viewid = $this->getViewIdByName($viewname, $module);
				}
			} else {
				$viewid = $viewname;
			}
			if ($this->isPermittedCustomView($viewid, $now_action, $this->customviewmodule) != 'yes')
				$viewid = 0;
		}

		if ($viewid == '' || $viewid == 0 || $this->isPermittedCustomView($viewid, $now_action, $module) != 'yes') {
			$query = "select cvid from vtiger_customview where viewname='All' and entitytype=? order by viewname";
			$cvresult = $adb->pquery($query, array($module));
			$viewid = $adb->query_result($cvresult, 0, 'cvid');
		}

		$_SESSION['lvs'][$module]["viewname"] = $viewid;
		return $viewid;
	}

	function getViewIdByName($viewname, $module) {
		global $adb;
		if (isset($viewname)) {
			$query = "select cvid from vtiger_customview where viewname=? and entitytype=? order by viewname";
			$cvresult = $adb->pquery($query, array($viewname, $module));
			$viewid = $adb->query_result($cvresult, 0, 'cvid');
			;
			return $viewid;
		} else {
			return 0;
		}
	}

	// return type array
	/** to get the details of a customview
	 * @param $cvid :: Type Integer
	 * @returns  $customviewlist Array in the following format
	 * $customviewlist = Array('viewname'=>value,
	 * 'setdefault'=>defaultchk,
	 * 'setmetrics'=>setmetricschk)
	 */
	function getCustomViewByCvid($cvid) {
		global $adb, $current_user;
		$tabid = getTabid($this->customviewmodule);

		require('user_privileges/user_privileges_' . $current_user->id . '.php');

		$ssql = "select vtiger_customview.* from vtiger_customview inner join vtiger_tab on vtiger_tab.name = vtiger_customview.entitytype";
		$ssql .= " where vtiger_customview.cvid=?";
		$sparams = array($cvid);

		if ($is_admin == false) {
			$ssql .= " and (vtiger_customview.status=0 or vtiger_customview.userid = ? or vtiger_customview.status = 3 or vtiger_customview.userid in(select vtiger_user2role.userid from vtiger_user2role inner join vtiger_users on vtiger_users.id=vtiger_user2role.userid inner join vtiger_role on vtiger_role.roleid=vtiger_user2role.roleid where vtiger_role.parentrole like '" . $current_user_parent_role_seq . "::%'))";
			array_push($sparams, $current_user->id);
		}
		$ssql .= ' order by viewname';
		$result = $adb->pquery($ssql, $sparams);

		$usercv_result = $adb->pquery("select default_cvid from vtiger_user_module_preferences where userid = ? and tabid = ?", array($current_user->id, $tabid));
		$def_cvid = $adb->query_result($usercv_result, 0, 'default_cvid');

		while ($cvrow = $adb->fetch_array($result)) {
			$customviewlist["viewname"] = $cvrow["viewname"];
			if ((isset($def_cvid) || $def_cvid != '') && $def_cvid == $cvid) {
				$customviewlist["setdefault"] = 1;
			} else {
				$customviewlist["setdefault"] = $cvrow["setdefault"];
			}
			$customviewlist["setmetrics"] = $cvrow["setmetrics"];
			$customviewlist["userid"] = $cvrow["userid"];
			$customviewlist["status"] = $cvrow["status"];
		}
		return $customviewlist;
	}

	/** to get the customviewCombo for the class variable customviewmodule
	 * @param $viewid :: Type Integer
	 * $viewid will make the corresponding selected
	 * @returns  $customviewCombo :: Type String
	 */
	function getCustomViewCombo($viewid = '', $markselected = true) {
		global $adb, $current_user;
		global $app_strings;
		$tabid = getTabid($this->customviewmodule);

		require('user_privileges/user_privileges_' . $current_user->id . '.php');

		$shtml_user = '';
		$shtml_pending = '';
		$shtml_public = '';
		$shtml_others = '';

		$selected = 'selected';
		if ($markselected == false)
			$selected = '';

		$ssql = "select vtiger_customview.*, vtiger_users.first_name,vtiger_users.last_name from vtiger_customview inner join vtiger_tab on vtiger_tab.name = vtiger_customview.entitytype
					left join vtiger_users on vtiger_customview.userid = vtiger_users.id ";
		$ssql .= " where vtiger_tab.tabid=?";
		$sparams = array($tabid);

		if ($is_admin == false) {
			$ssql .= " and (vtiger_customview.status=0 or vtiger_customview.userid = ? or vtiger_customview.status = 3 or vtiger_customview.userid in(select vtiger_user2role.userid from vtiger_user2role inner join vtiger_users on vtiger_users.id=vtiger_user2role.userid inner join vtiger_role on vtiger_role.roleid=vtiger_user2role.roleid where vtiger_role.parentrole like '" . $current_user_parent_role_seq . "::%'))";
			array_push($sparams, $current_user->id);
		}
		$ssql .= " ORDER BY viewname";
		$result = $adb->pquery($ssql, $sparams);
		while ($cvrow = $adb->fetch_array($result)) {
			if ($cvrow['viewname'] == 'All') {
				$cvrow['viewname'] = $app_strings['COMBO_ALL'];
			}

			$option = '';
			$viewname = $cvrow['viewname'];
			if ($cvrow['status'] == CV_STATUS_DEFAULT || $cvrow['userid'] == $current_user->id) {
				$disp_viewname = $viewname;
			} else {
				$userName = getFullNameFromArray('Users', $cvrow);
				$disp_viewname = $viewname . " [" . $userName . "] ";
			}


			if ($cvrow['setdefault'] == 1 && $viewid == '') {
				$option = "<option $selected value=\"" . $cvrow['cvid'] . "\">" . $disp_viewname . "</option>";
				$this->setdefaultviewid = $cvrow['cvid'];
			} elseif ($cvrow['cvid'] == $viewid) {
				$option = "<option $selected value=\"" . $cvrow['cvid'] . "\">" . $disp_viewname . "</option>";
				$this->setdefaultviewid = $cvrow['cvid'];
			} else {
				$option = "<option value=\"" . $cvrow['cvid'] . "\">" . $disp_viewname . "</option>";
			}

			// Add the option to combo box at appropriate section
			if ($option != '') {
				if ($cvrow['status'] == CV_STATUS_DEFAULT || $cvrow['userid'] == $current_user->id) {
					$shtml_user .= $option;
				} elseif ($cvrow['status'] == CV_STATUS_PUBLIC) {
					if ($shtml_public == '')
						$shtml_public = "<option disabled>--- " . $app_strings['LBL_PUBLIC'] . " ---</option>";
					$shtml_public .= $option;
				} elseif ($cvrow['status'] == CV_STATUS_PENDING) {
					if ($shtml_pending == '')
						$shtml_pending = "<option disabled>--- " . $app_strings['LBL_PENDING'] . " ---</option>";
					$shtml_pending .= $option;
				} else {
					if ($shtml_others == '')
						$shtml_others = "<option disabled>--- " . $app_strings['LBL_OTHERS'] . " ---</option>";
					$shtml_others .= $option;
				}
			}
		}
		$shtml = $shtml_user;
		if ($is_admin == true)
			$shtml .= $shtml_pending;
		$shtml = $shtml . $shtml_public . $shtml_others;
		return $shtml;
	}

	/** to get the getColumnsListbyBlock for the given module and Block
	 * @param $module :: Type String
	 * @param $block :: Type Integer
	 * @returns  $columnlist Array in the format
	 * $columnlist = Array ($fieldlabel =>'$fieldtablename:$fieldcolname:$fieldname:$module_$fieldlabel1:$fieldtypeofdata',
	  $fieldlabel1 =>'$fieldtablename1:$fieldcolname1:$fieldname1:$module_$fieldlabel11:$fieldtypeofdata1',
	  |
	  $fieldlabeln =>'$fieldtablenamen:$fieldcolnamen:$fieldnamen:$module_$fieldlabel1n:$fieldtypeofdatan')
	 */
	function getColumnsListbyBlock($module, $block) {
		global $adb, $mod_strings, $app_strings;
		$block_ids = explode(",", $block);
		$tabid = getTabid($module);
		global $current_user;
		require('user_privileges/user_privileges_' . $current_user->id . '.php');
		if (empty($this->meta) && $module != 'Calendar') {
			$this->meta = $this->getMeta($module, $current_user);
		}
		if ($tabid == 9)
			$tabid = "9,16";
		$display_type = " vtiger_field.displaytype in (1,2,3)";

		if ($is_admin == true || $profileGlobalPermission[1] == 0 || $profileGlobalPermission[2] == 0) {
			$tab_ids = explode(",", $tabid);
			$sql = "select * from vtiger_field ";
			$sql.= " where vtiger_field.tabid in (" . generateQuestionMarks($tab_ids) . ") and vtiger_field.block in (" . generateQuestionMarks($block_ids) . ") and vtiger_field.presence in (0,2) and";
			$sql.= $display_type;
			if ($tabid == 9 || $tabid == 16) {
				$sql.= " and vtiger_field.fieldname not in('notime','duration_minutes','duration_hours')";
			}
			$sql.= " order by sequence";
			$params = array($tab_ids, $block_ids);
		} else {
			$tab_ids = explode(",", $tabid);
			$profileList = getCurrentUserProfileList();
			$sql = "select * from vtiger_field inner join vtiger_profile2field on vtiger_profile2field.fieldid=vtiger_field.fieldid inner join vtiger_def_org_field on vtiger_def_org_field.fieldid=vtiger_field.fieldid ";
			$sql.= " where vtiger_field.tabid in (" . generateQuestionMarks($tab_ids) . ") and vtiger_field.block in (" . generateQuestionMarks($block_ids) . ") and";
			$sql.= "$display_type and vtiger_profile2field.visible=0 and vtiger_def_org_field.visible=0 and vtiger_field.presence in (0,2)";

			$params = array($tab_ids, $block_ids);

			if (php7_count($profileList) > 0) {
				$sql.= "  and vtiger_profile2field.profileid in (" . generateQuestionMarks($profileList) . ")";
				array_push($params, $profileList);
			}
			if ($tabid == 9 || $tabid == 16) {
				$sql.= " and vtiger_field.fieldname not in('notime','duration_minutes','duration_hours')";
			}

			$sql.= " group by columnname order by sequence";
		}
		if ($tabid == '9,16')
			$tabid = "9";
		$result = $adb->pquery($sql, $params);
		$noofrows = $adb->num_rows($result);
		//Added on 14-10-2005 -- added ticket id in list
		if ($module == 'HelpDesk' && $block == 25) {
			$module_columnlist['vtiger_crmentity:crmid::HelpDesk_Ticket_ID:I'] = 'Ticket ID';
		}
		//Added to include vtiger_activity type in vtiger_activity vtiger_customview list
		if ($module == 'Calendar' && $block == 19) {
			$module_columnlist['vtiger_activity:activitytype:activitytype:Calendar_Activity_Type:V'] = 'Activity Type';
		}

		if ($module == 'SalesOrder' && $block == 63)
			$module_columnlist['vtiger_crmentity:crmid::SalesOrder_Order_No:I'] = $app_strings['Order No'];

		if ($module == 'PurchaseOrder' && $block == 57)
			$module_columnlist['vtiger_crmentity:crmid::PurchaseOrder_Order_No:I'] = $app_strings['Order No'];

		if ($module == 'Quotes' && $block == 51)
			$module_columnlist['vtiger_crmentity:crmid::Quotes_Quote_No:I'] = $app_strings['Quote No'];
		if ($module != 'Calendar') {
			$moduleFieldList = $this->meta->getModuleFields();
		}
		for ($i = 0; $i < $noofrows; $i++) {
			$fieldtablename = $adb->query_result($result, $i, "tablename");
			$fieldcolname = $adb->query_result($result, $i, "columnname");
			$fieldname = $adb->query_result($result, $i, "fieldname");
			$fieldtype = $adb->query_result($result, $i, "typeofdata");
			$fieldtype = explode("~", $fieldtype);
			$fieldtypeofdata = $fieldtype[0];
			$fieldlabel = $adb->query_result($result, $i, "fieldlabel");
			$field = $moduleFieldList[$fieldname];
			if (!empty($field) && $field->getFieldDataType() == 'reference') {
				$fieldtypeofdata = 'V';
			} else {
				//Here we Changing the displaytype of the field. So that its criteria will be
				//displayed Correctly in Custom view Advance Filter.
				$fieldtypeofdata = ChangeTypeOfData_Filter($fieldtablename, $fieldcolname, $fieldtypeofdata);
			}
			if ($fieldlabel == "Start Date & Time") {
				$fieldlabel = "Start Date";
			}
			$fieldlabel1 = str_replace(" ", "_", $fieldlabel);
			$optionvalue = $fieldtablename . ":" . $fieldcolname . ":" . $fieldname . ":" . $module . "_" .
					$fieldlabel1 . ":" . $fieldtypeofdata;
			//added to escape attachments fields in customview as we have multiple attachments
			$fieldlabel = getTranslatedString($fieldlabel); //added to support i18n issue
			if ($module != 'HelpDesk' || $fieldname != 'filename')
				$module_columnlist[$optionvalue] = $fieldlabel;
			if ($fieldtype[1] == "M") {
				$this->mandatoryvalues[] = "'" . $optionvalue . "'";
				$this->showvalues[] = $fieldlabel;
				$this->data_type[$fieldlabel] = $fieldtype[1];
			}
		}
		return $module_columnlist;
	}

	/** to get the getModuleColumnsList for the given module
	 * @param $module :: Type String
	 * @returns  $ret_module_list Array in the following format
	 * $ret_module_list =
	  Array ('module' =>
	  Array('BlockLabel1' =>
	  Array('$fieldtablename:$fieldcolname:$fieldname:$module_$fieldlabel1:$fieldtypeofdata'=>$fieldlabel,
	  Array('$fieldtablename1:$fieldcolname1:$fieldname1:$module_$fieldlabel11:$fieldtypeofdata1'=>$fieldlabel1,
	  Array('BlockLabel2' =>
	  Array('$fieldtablename:$fieldcolname:$fieldname:$module_$fieldlabel1:$fieldtypeofdata'=>$fieldlabel,
	  Array('$fieldtablename1:$fieldcolname1:$fieldname1:$module_$fieldlabel11:$fieldtypeofdata1'=>$fieldlabel1,
	  |
	  Array('BlockLabeln' =>
	  Array('$fieldtablename:$fieldcolname:$fieldname:$module_$fieldlabel1:$fieldtypeofdata'=>$fieldlabel,
	  Array('$fieldtablename1:$fieldcolname1:$fieldname1:$module_$fieldlabel11:$fieldtypeofdata1'=>$fieldlabel1,


	 */
	function getModuleColumnsList($module) {

		$module_info = $this->getCustomViewModuleInfo($module);
		foreach ($this->module_list[$module] as $key => $value) {
			$columnlist = $this->getColumnsListbyBlock($module, $value);

			if (isset($columnlist)) {
				$ret_module_list[$module][$key] = $columnlist;
			}
		}
		return $ret_module_list;
	}

	/** to get the getModuleColumnsList for the given customview
	 * @param $cvid :: Type Integer
	 * @returns  $columnlist Array in the following format
	 * $columnlist = Array( $columnindex => $columnname,
	 * 			 $columnindex1 => $columnname1,
	 * 					|
	 * 			 $columnindexn => $columnnamen)
	 */
	function getColumnsListByCvid($cvid) {
		global $adb;

		$columnlist = Vtiger_Cache::get("cvColumnListByCvid", $cvid);
		if (!$columnlist) {
			$sSQL = "select vtiger_cvcolumnlist.* from vtiger_cvcolumnlist";
			$sSQL .= " inner join vtiger_customview on vtiger_customview.cvid = vtiger_cvcolumnlist.cvid";
			$sSQL .= " where vtiger_customview.cvid =? order by vtiger_cvcolumnlist.columnindex";
			$result = $adb->pquery($sSQL, array($cvid));
			while ($columnrow = $adb->fetch_array($result)) {
				$columnlist[$columnrow['columnindex']] = $columnrow['columnname'];
			}
			Vtiger_Cache::set("cvColumnListByCvid",$cvid, $columnlist);
		}
		return $columnlist;
	}

	/** to get the standard filter fields or the given module
	 * @param $module :: Type String
	 * @returns  $stdcriteria_list Array in the following format
	 * $stdcriteria_list = Array( $tablename:$columnname:$fieldname:$module_$fieldlabel => $fieldlabel,
	 * 			 $tablename1:$columnname1:$fieldname1:$module_$fieldlabel1 => $fieldlabel1,
	 * 					|
	 * 			 $tablenamen:$columnnamen:$fieldnamen:$module_$fieldlabeln => $fieldlabeln)
	 */
	function getStdCriteriaByModule($module) {
		global $adb;
		$tabid = getTabid($module);

		global $current_user;
		require('user_privileges/user_privileges_' . $current_user->id . '.php');

		$module_info = $this->getCustomViewModuleInfo($module);
		foreach ($this->module_list[$module] as $key => $blockid) {
			$blockids[] = $blockid;
		}

		if ($is_admin == true || $profileGlobalPermission[1] == 0 || $profileGlobalPermission[2] == 0) {
			$sql = "select * from vtiger_field inner join vtiger_tab on vtiger_tab.tabid = vtiger_field.tabid ";
			$sql.= " where vtiger_field.tabid=? and vtiger_field.block in (" . generateQuestionMarks($blockids) . ")
							and vtiger_field.uitype in (5,6,23,70)";
			$sql.= " and vtiger_field.presence in (0,2) order by vtiger_field.sequence";
			$params = array($tabid, $blockids);
		} else {
			$profileList = getCurrentUserProfileList();
			$sql = "select * from vtiger_field inner join vtiger_tab on vtiger_tab.tabid = vtiger_field.tabid inner join  vtiger_profile2field on vtiger_profile2field.fieldid=vtiger_field.fieldid inner join vtiger_def_org_field on vtiger_def_org_field.fieldid=vtiger_field.fieldid ";
			$sql.= " where vtiger_field.tabid=? and vtiger_field.block in (" . generateQuestionMarks($blockids) . ") and vtiger_field.uitype in (5,6,23,70)";
			$sql.= " and vtiger_profile2field.visible=0 and vtiger_def_org_field.visible=0 and vtiger_field.presence in (0,2)";

			$params = array($tabid, $blockids);

			if (php7_count($profileList) > 0) {
				$sql.= " and vtiger_profile2field.profileid in (" . generateQuestionMarks($profileList) . ")";
				array_push($params, $profileList);
			}

			$sql.= " order by vtiger_field.sequence";
		}

		$result = $adb->pquery($sql, $params);

		while ($criteriatyperow = $adb->fetch_array($result)) {
			$fieldtablename = $criteriatyperow["tablename"];
			$fieldcolname = $criteriatyperow["columnname"];
			$fieldlabel = $criteriatyperow["fieldlabel"];
			$fieldname = $criteriatyperow["fieldname"];
			$fieldlabel1 = str_replace(" ", "_", $fieldlabel);
			$optionvalue = $fieldtablename . ":" . $fieldcolname . ":" . $fieldname . ":" . $module . "_" . $fieldlabel1;
			$stdcriteria_list[$optionvalue] = $fieldlabel;
		}

		return $stdcriteria_list;
	}

	/**
	 *  Function which will give condition list for date fields
	 * @return array of std filter conditions
	 */
	function getStdFilterConditions() {
		return Array("custom","prevfy" ,"thisfy" ,"nextfy","prevfq",
			"thisfq","nextfq","yesterday","today","tomorrow",
			"lastweek","thisweek","nextweek","lastmonth","thismonth",
			"nextmonth","last7days","last14days","last30days","last60days","last90days",
			"last120days","next30days","next60days","next90days","next120days",
		);
	}

	/** to get the standard filter criteria
	 * @param $selcriteria :: Type String (optional)
	 * @returns  $filter Array in the following format
	 * $filter = Array( 0 => array('value'=>$filterkey,'text'=>$mod_strings[$filterkey],'selected'=>$selected)
	 * 		 1 => array('value'=>$filterkey1,'text'=>$mod_strings[$filterkey1],'selected'=>$selected)
	 * 		 		|
	 * 		 n => array('value'=>$filterkeyn,'text'=>$mod_strings[$filterkeyn],'selected'=>$selected)
	 */
	function getStdFilterCriteria($selcriteria = "") {
		global $mod_strings;
		$filter = array();

		$stdfilter = Array("custom" => "" . $mod_strings['Custom'] . "",
			"prevfy" => "" . $mod_strings['Previous FY'] . "",
			"thisfy" => "" . $mod_strings['Current FY'] . "",
			"nextfy" => "" . $mod_strings['Next FY'] . "",
			"prevfq" => "" . $mod_strings['Previous FQ'] . "",
			"thisfq" => "" . $mod_strings['Current FQ'] . "",
			"nextfq" => "" . $mod_strings['Next FQ'] . "",
			"yesterday" => "" . $mod_strings['Yesterday'] . "",
			"today" => "" . $mod_strings['Today'] . "",
			"tomorrow" => "" . $mod_strings['Tomorrow'] . "",
			"lastweek" => "" . $mod_strings['Last Week'] . "",
			"thisweek" => "" . $mod_strings['Current Week'] . "",
			"nextweek" => "" . $mod_strings['Next Week'] . "",
			"lastmonth" => "" . $mod_strings['Last Month'] . "",
			"thismonth" => "" . $mod_strings['Current Month'] . "",
			"nextmonth" => "" . $mod_strings['Next Month'] . "",
			"last7days" => "" . $mod_strings['Last 7 Days'] . "",
			"last30days" => "" . $mod_strings['Last 30 Days'] . "",
			"last60days" => "" . $mod_strings['Last 60 Days'] . "",
			"last90days" => "" . $mod_strings['Last 90 Days'] . "",
			"last120days" => "" . $mod_strings['Last 120 Days'] . "",
			"next30days" => "" . $mod_strings['Next 30 Days'] . "",
			"next60days" => "" . $mod_strings['Next 60 Days'] . "",
			"next90days" => "" . $mod_strings['Next 90 Days'] . "",
			"next120days" => "" . $mod_strings['Next 120 Days'] . "",
		);

		foreach ($stdfilter as $FilterKey => $FilterValue) {
			if ($FilterKey == $selcriteria) {
				$shtml['value'] = $FilterKey;
				$shtml['text'] = $FilterValue;
				$shtml['selected'] = "selected";
			} else {
				$shtml['value'] = $FilterKey;
				$shtml['text'] = $FilterValue;
				$shtml['selected'] = "";
			}
			$filter[] = $shtml;
		}
		return $filter;
	}

	/** to get the standard filter criteria scripts
	 * @returns  $jsStr : Type String
	 * This function will return the script to set the start data and end date
	 * for the standard selection criteria
	 */
	function getCriteriaJS() {

		$todayDateTime = new DateTimeField(date('Y-m-d H:i:s'));

		$tomorrow = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d") + 1, date("Y")));
		$tomorrowDateTime = new DateTimeField($tomorrow . ' ' . date('H:i:s'));

		$yesterday = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d") - 1, date("Y")));
		$yesterdayDateTime = new DateTimeField($yesterday . ' ' . date('H:i:s'));

		$currentmonth0 = date("Y-m-d", mktime(0, 0, 0, date("m"), "01", date("Y")));
		$currentMonthStartDateTime = new DateTimeField($currentmonth0 . ' ' . date('H:i:s'));
		$currentmonth1 = date("Y-m-t");
		$currentMonthEndDateTime = new DateTimeField($currentmonth1 . ' ' . date('H:i:s'));

		$lastmonth0 = date("Y-m-d", mktime(0, 0, 0, date("m") - 1, "01", date("Y")));
		$lastMonthStartDateTime = new DateTimeField($lastmonth0 . ' ' . date('H:i:s'));
		$lastmonth1 = date("Y-m-t", strtotime("-1 Month"));
		$lastMonthEndDateTime = new DateTimeField($lastmonth1 . ' ' . date('H:i:s'));

		$nextmonth0 = date("Y-m-d", mktime(0, 0, 0, date("m") + 1, "01", date("Y")));
		$nextMonthStartDateTime = new DateTimeField($nextmonth0 . ' ' . date('H:i:s'));
		$nextmonth1 = date("Y-m-t", strtotime("+1 Month"));
		$nextMonthEndDateTime = new DateTimeField($nextmonth1 . ' ' . date('H:i:s'));

		$lastweek0 = date("Y-m-d", strtotime("-2 week Sunday"));
		$lastWeekStartDateTime = new DateTimeField($lastweek0 . ' ' . date('H:i:s'));
		$lastweek1 = date("Y-m-d", strtotime("-1 week Saturday"));
		$lastWeekEndDateTime = new DateTimeField($lastweek1 . ' ' . date('H:i:s'));

		$thisweek0 = date("Y-m-d", strtotime("-1 week Sunday"));
		$thisWeekStartDateTime = new DateTimeField($thisweek0 . ' ' . date('H:i:s'));
		$thisweek1 = date("Y-m-d", strtotime("this Saturday"));
		$thisWeekEndDateTime = new DateTimeField($thisweek1 . ' ' . date('H:i:s'));

		$nextweek0 = date("Y-m-d", strtotime("this Sunday"));
		$nextWeekStartDateTime = new DateTimeField($nextweek0 . ' ' . date('H:i:s'));
		$nextweek1 = date("Y-m-d", strtotime("+1 week Saturday"));
		$nextWeekEndDateTime = new DateTimeField($nextweek1 . ' ' . date('H:i:s'));

		$next7days = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d") + 6, date("Y")));
		$next7DaysDateTime = new DateTimeField($next7days . ' ' . date('H:i:s'));

		$next30days = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d") + 29, date("Y")));
		$next30DaysDateTime = new DateTimeField($next30days . ' ' . date('H:i:s'));

		$next60days = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d") + 59, date("Y")));
		$next60DaysDateTime = new DateTimeField($next60days . ' ' . date('H:i:s'));

		$next90days = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d") + 89, date("Y")));
		$next90DaysDateTime = new DateTimeField($next90days . ' ' . date('H:i:s'));

		$next120days = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d") + 119, date("Y")));
		$next120DaysDateTime = new DateTimeField($next120days . ' ' . date('H:i:s'));

		$last7days = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d") - 6, date("Y")));
		$last7DaysDateTime = new DateTimeField($last7days . ' ' . date('H:i:s'));

		$last30days = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d") - 29, date("Y")));
		$last30DaysDateTime = new DateTimeField($last30days . ' ' . date('H:i:s'));

		$last60days = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d") - 59, date("Y")));
		$last60DaysDateTime = new DateTimeField($last60days . ' ' . date('H:i:s'));

		$last90days = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d") - 89, date("Y")));
		$last90DaysDateTime = new DateTimeField($last90days . ' ' . date('H:i:s'));

		$last120days = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d") - 119, date("Y")));
		$last120DaysDateTime = new DateTimeField($last120days . ' ' . date('H:i:s'));

		$currentFY0 = date("Y-m-d", mktime(0, 0, 0, "01", "01", date("Y")));
		$currentFYStartDateTime = new DateTimeField($currentFY0 . ' ' . date('H:i:s'));
		$currentFY1 = date("Y-m-t", mktime(0, 0, 0, "12", date("d"), date("Y")));
		$currentFYEndDateTime = new DateTimeField($currentFY1 . ' ' . date('H:i:s'));

		$lastFY0 = date("Y-m-d", mktime(0, 0, 0, "01", "01", date("Y") - 1));
		$lastFYStartDateTime = new DateTimeField($lastFY0 . ' ' . date('H:i:s'));
		$lastFY1 = date("Y-m-t", mktime(0, 0, 0, "12", date("d"), date("Y") - 1));
		$lastFYEndDateTime = new DateTimeField($lastFY1 . ' ' . date('H:i:s'));

		$nextFY0 = date("Y-m-d", mktime(0, 0, 0, "01", "01", date("Y") + 1));
		$nextFYStartDateTime = new DateTimeField($nextFY0 . ' ' . date('H:i:s'));
		$nextFY1 = date("Y-m-t", mktime(0, 0, 0, "12", date("d"), date("Y") + 1));
		$nextFYEndDateTime = new DateTimeField($nextFY1 . ' ' . date('H:i:s'));

		if (date("m") <= 3) {
			$cFq = date("Y-m-d", mktime(0, 0, 0, "01", "01", date("Y")));
			$cFqStartDateTime = new DateTimeField($cFq . ' ' . date('H:i:s'));
			$cFq1 = date("Y-m-d", mktime(0, 0, 0, "03", "31", date("Y")));
			$cFqEndDateTime = new DateTimeField($cFq1 . ' ' . date('H:i:s'));

			$nFq = date("Y-m-d", mktime(0, 0, 0, "04", "01", date("Y")));
			$nFqStartDateTime = new DateTimeField($nFq . ' ' . date('H:i:s'));
			$nFq1 = date("Y-m-d", mktime(0, 0, 0, "06", "30", date("Y")));
			$nFqEndDateTime = new DateTimeField($nFq1 . ' ' . date('H:i:s'));

			$pFq = date("Y-m-d", mktime(0, 0, 0, "10", "01", date("Y") - 1));
			$pFqStartDateTime = new DateTimeField($pFq . ' ' . date('H:i:s'));
			$pFq1 = date("Y-m-d", mktime(0, 0, 0, "12", "31", date("Y") - 1));
			$pFqEndDateTime = new DateTimeField($pFq1 . ' ' . date('H:i:s'));
		} else if (date("m") > 3 and date("m") <= 6) {

			$pFq = date("Y-m-d", mktime(0, 0, 0, "01", "01", date("Y")));
			$pFqStartDateTime = new DateTimeField($pFq . ' ' . date('H:i:s'));
			$pFq1 = date("Y-m-d", mktime(0, 0, 0, "03", "31", date("Y")));
			$pFqEndDateTime = new DateTimeField($pFq1 . ' ' . date('H:i:s'));

			$cFq = date("Y-m-d", mktime(0, 0, 0, "04", "01", date("Y")));
			$cFqStartDateTime = new DateTimeField($cFq . ' ' . date('H:i:s'));
			$cFq1 = date("Y-m-d", mktime(0, 0, 0, "06", "30", date("Y")));
			$cFqEndDateTime = new DateTimeField($cFq1 . ' ' . date('H:i:s'));

			$nFq = date("Y-m-d", mktime(0, 0, 0, "07", "01", date("Y")));
			$nFqStartDateTime = new DateTimeField($nFq . ' ' . date('H:i:s'));
			$nFq1 = date("Y-m-d", mktime(0, 0, 0, "09", "30", date("Y")));
			$nFqEndDateTime = new DateTimeField($nFq1 . ' ' . date('H:i:s'));
		} else if (date("m") > 6 and date("m") <= 9) {

			$nFq = date("Y-m-d", mktime(0, 0, 0, "10", "01", date("Y")));
			$nFqStartDateTime = new DateTimeField($nFq . ' ' . date('H:i:s'));
			$nFq1 = date("Y-m-d", mktime(0, 0, 0, "12", "31", date("Y")));
			$nFqEndDateTime = new DateTimeField($nFq1 . ' ' . date('H:i:s'));

			$pFq = date("Y-m-d", mktime(0, 0, 0, "04", "01", date("Y")));
			$pFqStartDateTime = new DateTimeField($pFq . ' ' . date('H:i:s'));
			$pFq1 = date("Y-m-d", mktime(0, 0, 0, "06", "30", date("Y")));
			$pFqEndDateTime = new DateTimeField($pFq1 . ' ' . date('H:i:s'));

			$cFq = date("Y-m-d", mktime(0, 0, 0, "07", "01", date("Y")));
			$cFqStartDateTime = new DateTimeField($cFq . ' ' . date('H:i:s'));
			$cFq1 = date("Y-m-d", mktime(0, 0, 0, "09", "30", date("Y")));
			$cFqEndDateTime = new DateTimeField($cFq1 . ' ' . date('H:i:s'));
		} else if (date("m") > 9 and date("m") <= 12) {
			$nFq = date("Y-m-d", mktime(0, 0, 0, "01", "01", date("Y") + 1));
			$nFqStartDateTime = new DateTimeField($nFq . ' ' . date('H:i:s'));
			$nFq1 = date("Y-m-d", mktime(0, 0, 0, "03", "31", date("Y") + 1));
			$nFqEndDateTime = new DateTimeField($nFq1 . ' ' . date('H:i:s'));

			$pFq = date("Y-m-d", mktime(0, 0, 0, "07", "01", date("Y")));
			$pFqStartDateTime = new DateTimeField($pFq . ' ' . date('H:i:s'));
			$pFq1 = date("Y-m-d", mktime(0, 0, 0, "09", "30", date("Y")));
			$pFqEndDateTime = new DateTimeField($pFq1 . ' ' . date('H:i:s'));

			$cFq = date("Y-m-d", mktime(0, 0, 0, "10", "01", date("Y")));
			$cFqStartDateTime = new DateTimeField($cFq . ' ' . date('H:i:s'));
			$cFq1 = date("Y-m-d", mktime(0, 0, 0, "12", "31", date("Y")));
			$cFqEndDateTime = new DateTimeField($cFq1 . ' ' . date('H:i:s'));
		}

		$sjsStr = '<script language="JavaScript" type="text/javaScript">
			function showDateRange( type ) {
				if (type!="custom") {
					document.CustomView.startdate.readOnly=true
					document.CustomView.enddate.readOnly=true
					getObj("jscal_trigger_date_start").style.visibility="hidden"
					getObj("jscal_trigger_date_end").style.visibility="hidden"
				} else {
					document.CustomView.startdate.readOnly=false
					document.CustomView.enddate.readOnly=false
					getObj("jscal_trigger_date_start").style.visibility="visible"
					getObj("jscal_trigger_date_end").style.visibility="visible"
				}
				if( type == "today" ) {
					document.CustomView.startdate.value = "' . $todayDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $todayDateTime->getDisplayDate() . '";

				} else if( type == "yesterday" ) {
					document.CustomView.startdate.value = "' . $yesterdayDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $yesterdayDateTime->getDisplayDate() . '";

				} else if( type == "tomorrow" ) {
					document.CustomView.startdate.value = "' . $tomorrowDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $tomorrowDateTime->getDisplayDate() . '";

				} else if( type == "thisweek" ) {
					document.CustomView.startdate.value = "' . $thisWeekStartDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $thisWeekEndDateTime->getDisplayDate() . '";

				} else if( type == "lastweek" ) {
					document.CustomView.startdate.value = "' . $lastWeekStartDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $lastWeekEndDateTime->getDisplayDate() . '";

				} else if( type == "nextweek" ) {
					document.CustomView.startdate.value = "' . $nextWeekStartDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $nextWeekEndDateTime->getDisplayDate() . '";

				} else if( type == "thismonth" ) {
					document.CustomView.startdate.value = "' . $currentMonthStartDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $currentMonthEndDateTime->getDisplayDate() . '";

				} else if( type == "lastmonth" ) {
					document.CustomView.startdate.value = "' . $lastMonthStartDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $lastMonthEndDateTime->getDisplayDate() . '";

				} else if( type == "nextmonth" ) {
					document.CustomView.startdate.value = "' . $nextMonthStartDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $nextMonthEndDateTime->getDisplayDate() . '";

				} else if( type == "next7days" ) {
					document.CustomView.startdate.value = "' . $todayDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $next7DaysDateTime->getDisplayDate() . '";

				} else if( type == "next30days" ) {
					document.CustomView.startdate.value = "' . $todayDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $next30DaysDateTime->getDisplayDate() . '";

				} else if( type == "next60days" ) {
					document.CustomView.startdate.value = "' . $todayDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $next60DaysDateTime->getDisplayDate() . '";

				} else if( type == "next90days" ) {
					document.CustomView.startdate.value = "' . $todayDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $next90DaysDateTime->getDisplayDate() . '";

				} else if( type == "next120days" ) {
					document.CustomView.startdate.value = "' . $todayDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $next120DaysDateTime->getDisplayDate() . '";

				} else if( type == "last7days" ) {
					document.CustomView.startdate.value = "' . $last7DaysDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value =  "' . $todayDateTime->getDisplayDate() . '";

				} else if( type == "last30days" ) {
					document.CustomView.startdate.value = "' . $last30DaysDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $todayDateTime->getDisplayDate() . '";

				} else if( type == "last60days" ) {
					document.CustomView.startdate.value = "' . $last60DaysDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $todayDateTime->getDisplayDate() . '";

				} else if( type == "last90days" ) {
					document.CustomView.startdate.value = "' . $last90DaysDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $todayDateTime->getDisplayDate() . '";

				} else if( type == "last120days" ) {
					document.CustomView.startdate.value = "' . $last120DaysDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $todayDateTime->getDisplayDate() . '";

				} else if( type == "thisfy" ) {
					document.CustomView.startdate.value = "' . $currentFYStartDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $currentFYEndDateTime->getDisplayDate() . '";

				} else if( type == "prevfy" ) {
					document.CustomView.startdate.value = "' . $lastFYStartDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $lastFYEndDateTime->getDisplayDate() . '";

				} else if( type == "nextfy" ) {
					document.CustomView.startdate.value = "' . $nextFYStartDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $nextFYEndDateTime->getDisplayDate() . '";

				} else if( type == "nextfq" ) {
					document.CustomView.startdate.value = "' . $nFqStartDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $nFqEndDateTime->getDisplayDate() . '";

				} else if( type == "prevfq" ) {
					document.CustomView.startdate.value = "' . $pFqStartDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $pFqEndDateTime->getDisplayDate() . '";

				} else if( type == "thisfq" ) {
					document.CustomView.startdate.value = "' . $cFqStartDateTime->getDisplayDate() . '";
					document.CustomView.enddate.value = "' . $cFqEndDateTime->getDisplayDate() . '";

				} else {
					document.CustomView.startdate.value = "";
					document.CustomView.enddate.value = "";
				}
			}
		</script>';

		return $sjsStr;
	}

	/** to get the standard filter for the given customview Id
	 * @param $cvid :: Type Integer
	 * @returns  $stdfilterlist Array in the following format
	 * $stdfilterlist = Array( 'columnname' =>  $tablename:$columnname:$fieldname:$module_$fieldlabel,'stdfilter'=>$stdfilter,'startdate'=>$startdate,'enddate'=>$enddate)
	 */
	function getStdFilterByCvid($cvid) {
		global $adb;
		$stdfilterrow = Vtiger_Cache::get("cvStdFilterById",$cvid);
		if(!$stdfilterrow){
			$sSQL = "select vtiger_cvstdfilter.* from vtiger_cvstdfilter inner join vtiger_customview on vtiger_customview.cvid = vtiger_cvstdfilter.cvid";
			$sSQL .= " where vtiger_cvstdfilter.cvid=?";

			$result = $adb->pquery($sSQL, array($cvid));
			$data = $adb->fetch_array($result);
			$stdfilterrow = $data ? $data : null ;
			Vtiger_Cache::set("cvStdFilterById",$cvid,$stdfilterrow);
		}
		return $stdfilterrow ? $this->resolveDateFilterValue($stdfilterrow) : array();
	}

	function resolveDateFilterValue ($dateFilterRow) {
		$stdfilterlist = array();
		$stdfilterlist["columnname"] = $dateFilterRow["columnname"];
		$stdfilterlist["stdfilter"] = $dateFilterRow["stdfilter"];

		if ($dateFilterRow["stdfilter"] == "custom" || $dateFilterRow["stdfilter"] == "" || $dateFilterRow["stdfilter"] == "e" || $dateFilterRow["stdfilter"] == "n") {
			if ($dateFilterRow["startdate"] != "0000-00-00" && $dateFilterRow["startdate"] != "") {
				$startDateTime = new DateTimeField($dateFilterRow["startdate"]);
				$stdfilterlist["startdate"] = $startDateTime->getDisplayDate();
			}
			if ($dateFilterRow["enddate"] != "0000-00-00" && $dateFilterRow["enddate"] != "") {
				$endDateTime = new DateTimeField($dateFilterRow["enddate"]);
				$stdfilterlist["enddate"] = $endDateTime->getDisplayDate();
			}
		} else { //if it is not custom get the date according to the selected duration
			$specialDateFilters = array('yesterday','today','tomorrow');
			$datefilter = $this->getDateforStdFilterBytype($dateFilterRow["stdfilter"]);

			if(in_array($dateFilterRow["stdfilter"], $specialDateFilters)) {
				$currentDate = DateTimeField::convertToUserTimeZone(date('Y-m-d H:i:s'));
				$startDateTime = new DateTimeField($datefilter[0] . ' ' . $currentDate->format('H:i:s'));
				$endDateTime = new DateTimeField($datefilter[1] . ' ' . $currentDate->format('H:i:s'));
			} else {
				$startDateTime = new DateTimeField($datefilter[0]);
				$endDateTime = new DateTimeField($datefilter[1]);
			}

			$stdfilterlist["startdate"] = $startDateTime->getDisplayDate();
			$stdfilterlist["enddate"] = $endDateTime->getDisplayDate();
		}

		return $stdfilterlist;
	}

	/** to get the Advanced filter for the given customview Id
	 * @param $cvid :: Type Integer
	 * @returns  $advfilterlist Array
	 */
	function getAdvFilterByCvid($cvid) {

		global $adb, $log, $default_charset;

			$advft_criteria = Vtiger_Cache::get('advftCriteria',$cvid);
			if(!$advft_criteria){
				// Not a good approach to get all the fields if not required(May leads to Performance issue)
				$sql = 'SELECT groupid,group_condition FROM vtiger_cvadvfilter_grouping WHERE cvid = ? ORDER BY groupid';
				$groupsresult = $adb->pquery($sql, array($cvid));

				$i = 1;
				$j = 0;
				while ($relcriteriagroup = $adb->fetch_array($groupsresult)) {
				$groupId = $relcriteriagroup["groupid"];
				$groupCondition = $relcriteriagroup["group_condition"];

				$ssql = 'select vtiger_cvadvfilter.* from vtiger_customview
				inner join vtiger_cvadvfilter on vtiger_cvadvfilter.cvid = vtiger_customview.cvid
				left join vtiger_cvadvfilter_grouping on vtiger_cvadvfilter.cvid = vtiger_cvadvfilter_grouping.cvid
				and vtiger_cvadvfilter.groupid = vtiger_cvadvfilter_grouping.groupid';
				$ssql.= " where vtiger_customview.cvid = ? AND vtiger_cvadvfilter.groupid = ? order by vtiger_cvadvfilter.columnindex";

				$result = $adb->pquery($ssql, array($cvid, $groupId));
				$noOfColumns = $adb->num_rows($result);
				if ($noOfColumns <= 0)
				continue;

				while ($relcriteriarow = $adb->fetch_array($result)) {
					$columnIndex = $relcriteriarow["columnindex"];
					$criteria = array();
					$criteria['columnname'] = html_entity_decode($relcriteriarow["columnname"], ENT_QUOTES, $default_charset);
					$criteria['comparator'] = $relcriteriarow["comparator"];
					$advfilterval = html_entity_decode($relcriteriarow["value"], ENT_QUOTES, $default_charset);
					$col = explode(":", $relcriteriarow["columnname"]);
					$temp_val = explode(",", $relcriteriarow["value"]);

					$advFilterColumn = $criteria['columnname'];
					$advFilterComparator = $criteria['comparator'];
					$advFilterColumnCondition = $criteria['column_condition'];

					$columnInfo = explode(":", $advFilterColumn);
					$fieldName = $columnInfo[2];
					$moduleName = $this->customviewmodule;
					$moduleModel = Vtiger_Module_Model::getInstance($moduleName);
					preg_match('/(\w+) ; \((\w+)\) (\w+)/', $fieldName, $matches);

					if (php7_count($matches) != 0) {
						list($full, $referenceParentField, $referenceModule, $referenceFieldName) = $matches;
					}
					if ($referenceParentField) {
						$referenceModuleModel = Vtiger_Module_Model::getInstance($referenceModule);
						$fieldModel = $referenceModuleModel->getField($referenceFieldName);
					} else {
						$fieldModel = $moduleModel->getField($fieldName);
					}
					//Required if Events module fields are selected for the condition
					if (!$fieldModel) {
						$modulename = $moduleModel->get('name');
						if ($modulename == 'Calendar') {
							$eventModuleModel = Vtiger_Module_model::getInstance('Events');
							$fieldModel = $eventModuleModel->getField($fieldName);
						}
					}

					if($fieldModel) {
						$fieldType = $fieldModel->getFieldDataType();
					}

					if ($fieldType == 'currency') {
						if ($fieldModel->get('uitype') == '72') {
							// Some of the currency fields like Unit Price, Totoal , Sub-total - doesn't need currency conversion during save
							$advfilterval = CurrencyField::convertToUserFormat($advfilterval, null, true);
						} else {
							$advfilterval = CurrencyField::convertToUserFormat($advfilterval);
						}
					}

					$specialDateTimeConditions = Vtiger_Functions::getSpecialDateTimeCondtions();
					if (($col[4] == 'D' || ($col[4] == 'T' && $col[1] != 'time_start' && $col[1] != 'time_end') || ($col[4] == 'DT')) && !in_array($criteria['comparator'], $specialDateTimeConditions)) {
						$val = Array();
						for ($x = 0; $x < php7_count($temp_val); $x++) {
							if(empty($temp_val[$x])) {
								$val[$x] = '';
							} else if ($col[4] == 'D') {
								/** while inserting in db for due_date it was taking date and time values also as it is
								 * date time field. We only need to take date from that value
								 */
								if($col[0] == "vtiger_activity" && $col[1] == "due_date" ){
									$values = explode(' ', $temp_val[$x]);
									$temp_val[$x] = $values[0];
								}
								$date = new DateTimeField(trim($temp_val[$x]));
								$val[$x] = $date->getDisplayDate();
							} elseif ($col[4] == 'DT') {
								$comparator = array('e','n','b','a');
								if(in_array($criteria['comparator'], $comparator)) {
									$originalValue = $temp_val[$x];
									$dateTime = explode(' ',$originalValue);
									$temp_val[$x] = $dateTime[0];
								}
								$date = new DateTimeField(trim($temp_val[$x]));
								$val[$x] = $date->getDisplayDateTimeValue();
							} else {
								$date = new DateTimeField(trim($temp_val[$x]));
								$val[$x] = $date->getDisplayTime();
							}
						}
						$advfilterval = implode(",", $val);
					}
					$criteria['value'] = $advfilterval;
					$criteria['column_condition'] = $relcriteriarow["column_condition"];

					$advft_criteria[$i]['columns'][$j] = $criteria;
					$advft_criteria[$i]['condition'] = $groupCondition;
					$j++;
				}
				if (!empty($advft_criteria[$i]['columns'][$j - 1]['column_condition'])) {
					$advft_criteria[$i]['columns'][$j - 1]['column_condition'] = '';
				}
				$i++;
			}
			// Clear the condition (and/or) for last group, if any.
			if (!empty($advft_criteria[$i - 1]['condition']))
				$advft_criteria[$i - 1]['condition'] = '';

			$advft_criteria = $advft_criteria ? $advft_criteria : null;
			Vtiger_Cache::set('advftCriteria',$cvid,$advft_criteria);
		}
		return $advft_criteria ? $advft_criteria : array();
	}

	/**
	 * Cache information to perform re-lookups
	 *
	 * @var String
	 */
	protected $_fieldby_tblcol_cache = array();

	/**
	 * Function to check if field is present based on
	 *
	 * @param String $columnname
	 * @param String $tablename
	 */
	function isFieldPresent_ByColumnTable($columnname, $tablename) {
		global $adb;

		if (!isset($this->_fieldby_tblcol_cache[$tablename])) {
			$query = 'SELECT columnname FROM vtiger_field WHERE tablename = ? and presence in (0,2)';

			$result = $adb->pquery($query, array($tablename));
			$numrows = $adb->num_rows($result);

			if ($numrows) {
				$this->_fieldby_tblcol_cache[$tablename] = array();
				for ($index = 0; $index < $numrows; ++$index) {
					$this->_fieldby_tblcol_cache[$tablename][] = $adb->query_result($result, $index, 'columnname');
				}
			}
		}
		// If still the field was not found (might be disabled or deleted?)
		if (!isset($this->_fieldby_tblcol_cache[$tablename])) {
			return false;
		}
		return in_array($columnname, $this->_fieldby_tblcol_cache[$tablename]);
	}

	/** to get the customview Columnlist Query for the given customview Id
	 * @param $cvid :: Type Integer
	 * @returns  $getCvColumnList as a string
	 * This function will return the columns for the given customfield in comma seperated values in the format
	 * $tablename.$columnname,$tablename1.$columnname1, ------ $tablenamen.$columnnamen
	 *
	 */
	function getCvColumnListSQL($cvid) {
		global $adb;
		$columnslist = $this->getColumnsListByCvid($cvid);
		if (isset($columnslist)) {
			foreach ($columnslist as $columnname => $value) {
				$tablefield = "";
				if ($value != "") {
					$list = explode(":", $value);

					//Added For getting status for Activities -Jaguar
					$sqllist_column = $list[0] . "." . $list[1];
					if ($this->customviewmodule == "Calendar") {
						if ($list[1] == "status" || $list[1] == "eventstatus") {
							$sqllist_column = "case when (vtiger_activity.status not like '') then vtiger_activity.status else vtiger_activity.eventstatus end as activitystatus";
						}
					}
					//Added for assigned to sorting
					if ($list[1] == "smownerid") {
						$userNameSql = getSqlForNameInDisplayFormat(array('last_name' => 'vtiger_users.last_name', 'first_name' => 'vtiger_users.first_name', ), 'Users');
						$sqllist_column = "case when (vtiger_users.user_name not like '') then $userNameSql else vtiger_groups.groupname end as user_name";
					}
					if ($list[0] == "vtiger_contactdetails" && $list[1] == "lastname")
						$sqllist_column = "vtiger_contactdetails.lastname,vtiger_contactdetails.firstname";
					$sqllist[] = $sqllist_column;
					//Ends

					$tablefield[$list[0]] = $list[1];

					//Changed as the replace of module name may replace the string if the fieldname has module name in it -- Jeri
					$fieldinfo = explode('_', $list[3], 2);
					$fieldlabel = $fieldinfo[1];
					$fieldlabel = str_replace("_", " ", $fieldlabel);

					if ($this->isFieldPresent_ByColumnTable($list[1], $list[0])) {

						$this->list_fields[$fieldlabel] = $tablefield;
						$this->list_fields_name[$fieldlabel] = $list[2];
					}
				}
			}
			$returnsql = implode(",", $sqllist);
		}
		return $returnsql;
	}

	/** to get the customview stdFilter Query for the given customview Id
	 * @param $cvid :: Type Integer
	 * @returns  $stdfiltersql as a string
	 * This function will return the standard filter criteria for the given customfield
	 *
	 */
	function getCVStdFilterSQL($cvid) {
		global $adb;

		$stdfiltersql = '';
		$stdfilterlist = array();

		$sSQL = "select vtiger_cvstdfilter.* from vtiger_cvstdfilter inner join vtiger_customview on vtiger_customview.cvid = vtiger_cvstdfilter.cvid";
		$sSQL .= " where vtiger_cvstdfilter.cvid=?";

		$result = $adb->pquery($sSQL, array($cvid));
		$stdfilterrow = $adb->fetch_array($result);

		$stdfilterlist = array();
		$stdfilterlist["columnname"] = $stdfilterrow["columnname"];
		$stdfilterlist["stdfilter"] = $stdfilterrow["stdfilter"];

		if ($stdfilterrow["stdfilter"] == "custom" || $stdfilterrow["stdfilter"] == "") {
			if ($stdfilterrow["startdate"] != "0000-00-00" && $stdfilterrow["startdate"] != "") {
				$stdfilterlist["startdate"] = $stdfilterrow["startdate"];
			}
			if ($stdfilterrow["enddate"] != "0000-00-00" && $stdfilterrow["enddate"] != "") {
				$stdfilterlist["enddate"] = $stdfilterrow["enddate"];
			}
		} else { //if it is not custom get the date according to the selected duration
			$datefilter = $this->getDateforStdFilterBytype($stdfilterrow["stdfilter"]);
			$stdfilterlist["startdate"] = $datefilter[0];
			$stdfilterlist["enddate"] = $datefilter[1];
		}

		if (isset($stdfilterlist)) {

			foreach ($stdfilterlist as $columnname => $value) {

				if ($columnname == "columnname") {
					$filtercolumn = $value;
				} elseif ($columnname == "stdfilter") {
					$filtertype = $value;
				} elseif ($columnname == "startdate") {
					$startDateTime = new DateTimeField($value . ' ' . date('H:i:s'));
					$userStartDate = $startDateTime->getDisplayDate();
					$userStartDateTime = new DateTimeField($userStartDate . ' 00:00:00');
					$startDateTime = $userStartDateTime->getDBInsertDateTimeValue();
				} elseif ($columnname == "enddate") {
					$endDateTime = new DateTimeField($value . ' ' . date('H:i:s'));
					$userEndDate = $endDateTime->getDisplayDate();
					$userEndDateTime = new DateTimeField($userEndDate . ' 23:59:00');
					$endDateTime = $userEndDateTime->getDBInsertDateTimeValue();
				}
				if ($startDateTime != "" && $endDateTime != "") {
					$columns = explode(":", $filtercolumn);
					// Fix for http://trac.vtiger.com/cgi-bin/trac.cgi/ticket/5423
					if ($columns[1] == 'birthday') {
						$tableColumnSql = "DATE_FORMAT(" . $columns[0] . "." . $columns[1] . ", '%m%d')";
						$startDateTime = "DATE_FORMAT('$startDate', '%m%d')";
						$endDateTime = "DATE_FORMAT('$endDate', '%m%d')";
						$stdfiltersql = $tableColumnSql . " BETWEEN " . $startDateTime . " and " . $endDateTime;
					} else {
						if ($this->customviewmodule == 'Calendar' && ($columns[1] == 'date_start' || $columns[1] == 'due_date')) {
							$tableColumnSql = '';
							if ($columns[1] == 'date_start') {
								$tableColumnSql = "CAST((CONCAT(date_start,' ',time_start)) AS DATETIME)";
							} else {
								$tableColumnSql = "CAST((CONCAT(due_date,' ',time_end)) AS DATETIME)";
							}
						} else {
							$tableColumnSql = $columns[0] . "." . $columns[1];
						}
						$stdfiltersql = $tableColumnSql . " BETWEEN '" . $startDateTime . "' and '" . $endDateTime . "'";
					}
				}
			}
		}
		return $stdfiltersql;
	}

	/** to get the customview AdvancedFilter Query for the given customview Id
	 * @param $cvid :: Type Integer
	 * @returns  $advfiltersql as a string
	 * This function will return the advanced filter criteria for the given customfield
	 *
	 */
	// Needs to be modified according to the new advanced filter (support for grouping).
	// Not modified as of now, as this function is not used for now (Instead Query Generator is used for better performance).
	function getCVAdvFilterSQL($cvid) {
		global $current_user;

		$advfilter = $this->getAdvFilterByCvid($cvid);

		$advcvsql = "";

		foreach ($advfilter as $groupid => $groupinfo) {

			$groupcolumns = $groupinfo["columns"];
			$groupcondition = $groupinfo["condition"];
			$advfiltergroupsql = "";

			foreach ($groupcolumns as $columnindex => $columninfo) {
				$columnname = $columninfo['columnname'];
				$comparator = $columninfo['comparator'];
				$value = $columninfo['value'];
				$columncondition = $columninfo['column_condition'];

				$columns = explode(":", $columnname);
				$datatype = (isset($columns[4])) ? $columns[4] : "";

				if ($columnname != "" && $comparator != "") {
					$valuearray = explode(",", trim($value));

					if (isset($valuearray) && php7_count($valuearray) > 1 && $comparator != 'bw') {
						$advorsql = "";
						for ($n = 0; $n < php7_count($valuearray); $n++) {
							$advorsql[] = $this->getRealValues($columns[0], $columns[1], $comparator, trim($valuearray[$n]), $datatype);
						}
						//If negative logic filter ('not equal to', 'does not contain') is used, 'and' condition should be applied instead of 'or'
						if ($comparator == 'n' || $comparator == 'k')
							$advorsqls = implode(" and ", $advorsql);
						else
							$advorsqls = implode(" or ", $advorsql);
						$advfiltersql = " (" . $advorsqls . ") ";
					}
					elseif ($comparator == 'bw' && php7_count($valuearray) == 2) {
						$advfiltersql = "(" . $columns[0] . "." . $columns[1] . " between '" . getValidDBInsertDateTimeValue(trim($valuearray[0]), $datatype) . "' and '" . getValidDBInsertDateTimeValue(trim($valuearray[1]), $datatype) . "')";
					}
					elseif ($comparator == 'y') {
						$advfiltersql = sprintf("(%s.%s IS NULL OR %s.%s = '')", $columns[0], $columns[1], $columns[0], $columns[1]);
					} else {
						//Added for getting vtiger_activity Status -Jaguar
						if ($this->customviewmodule == "Calendar" && ($columns[1] == "status" || $columns[1] == "eventstatus")) {
							if (getFieldVisibilityPermission("Calendar", $current_user->id, 'taskstatus') == '0') {
								$advfiltersql = "case when (vtiger_activity.status not like '') then vtiger_activity.status else vtiger_activity.eventstatus end" . $this->getAdvComparator($comparator, trim($value), $datatype);
							}
							else
								$advfiltersql = "vtiger_activity.eventstatus" . $this->getAdvComparator($comparator, trim($value), $datatype);
						}
						elseif ($this->customviewmodule == "Documents" && $columns[1] == 'folderid') {
							$advfiltersql = "vtiger_attachmentsfolder.foldername" . $this->getAdvComparator($comparator, trim($value), $datatype);
						} elseif ($this->customviewmodule == "Assets") {
							if ($columns[1] == 'account') {
								$advfiltersql = "vtiger_account.accountname" . $this->getAdvComparator($comparator, trim($value), $datatype);
							}
							if ($columns[1] == 'product') {
								$advfiltersql = "vtiger_products.productname" . $this->getAdvComparator($comparator, trim($value), $datatype);
							}
							if ($columns[1] == 'invoiceid') {
								$advfiltersql = "vtiger_invoice.subject" . $this->getAdvComparator($comparator, trim($value), $datatype);
							}
						} else {
							$advfiltersql = $this->getRealValues($columns[0], $columns[1], $comparator, trim($value), $datatype);
						}
					}

					$advfiltergroupsql .= $advfiltersql;
					if ($columncondition != NULL && $columncondition != '' && php7_count($groupcolumns) > $columnindex) {
						$advfiltergroupsql .= ' ' . $columncondition . ' ';
					}
				}
			}

			if (trim($advfiltergroupsql) != "") {
				$advfiltergroupsql = "( $advfiltergroupsql ) ";
				if ($groupcondition != NULL && $groupcondition != '' && $advfilter > $groupid) {
					$advfiltergroupsql .= ' ' . $groupcondition . ' ';
				}

				$advcvsql .= $advfiltergroupsql;
			}
		}
		if (trim($advcvsql) != "")
			$advcvsql = '(' . $advcvsql . ')';
		return $advcvsql;
	}

	/** to get the realvalues for the given value
	 * @param $tablename :: type string
	 * @param $fieldname :: type string
	 * @param $comparator :: type string
	 * @param $value :: type string
	 * @returns  $value as a string in the following format
	 * 	  $tablename.$fieldname comparator
	 */
	function getRealValues($tablename, $fieldname, $comparator, $value, $datatype) {
		//we have to add the fieldname/tablename.fieldname and the corresponding value (which we want) we can add here. So that when these LHS field comes then RHS value will be replaced for LHS in the where condition of the query
		global $adb, $mod_strings, $currentModule, $current_user;
		//Added for proper check of contact name in advance filter
		if ($tablename == "vtiger_contactdetails" && $fieldname == "lastname")
			$fieldname = "contactid";

		$contactid = "vtiger_contactdetails.lastname";
		if ($currentModule != "Contacts" && $currentModule != "Leads" && $currentModule != 'Campaigns') {
			$contactid = getSqlForNameInDisplayFormat(array('lastname' => 'vtiger_contactdetails.lastname', 'firstname' => 'vtiger_contactdetails.firstname'), 'Contacts');
		}
		$change_table_field = Array(
			"product_id" => "vtiger_products.productname",
			"contactid" => 'trim(' . $contactid . ')',
			"contact_id" => 'trim(' . $contactid . ')',
			"accountid" => "", //in cvadvfilter accountname is stored for Contact, Potential, Quotes, SO, Invoice
			"account_id" => "", //Same like accountid. No need to change
			"vendorid" => "vtiger_vendor.vendorname",
			"vendor_id" => "vtiger_vendor.vendorname",
			"potentialid" => "vtiger_potential.potentialname",
			"vtiger_account.parentid" => "vtiger_account2.accountname",
			"quoteid" => "vtiger_quotes.subject",
			"salesorderid" => "vtiger_salesorder.subject",
			"campaignid" => "vtiger_campaign.campaignname",
			"vtiger_contactdetails.reportsto" => getSqlForNameInDisplayFormat(array('lastname' => 'vtiger_contactdetails2.lastname', 'firstname' => 'vtiger_contactdetails2.firstname'), 'Contacts'),
			"vtiger_pricebook.currency_id" => "vtiger_currency_info.currency_name",
		);

		if ($fieldname == "smownerid" || $fieldname == 'modifiedby') {
			if($fieldname == "smownerid") {
				$tableNameSuffix = '';
			} elseif($fieldname == "modifiedby") {
				$tableNameSuffix = '2';
			}
			$userNameSql = getSqlForNameInDisplayFormat(array('last_name' => 'vtiger_users'.$tableNameSuffix.'.last_name', 'first_name' => 'vtiger_users'.$tableNameSuffix.'.first_name', ), 'Users');
			$temp_value = '( trim(' . $userNameSql . ')' . $this->getAdvComparator($comparator, $value, $datatype);
			$temp_value.= " OR  vtiger_groups$tableNameSuffix.groupname" . $this->getAdvComparator($comparator, $value, $datatype) . ')';
			$value = $temp_value; // Hot fix: removed unbalanced closing bracket ")";
		} elseif ($fieldname == "inventorymanager") {
			$value = $tablename . "." . $fieldname . $this->getAdvComparator($comparator, getUserId_Ol($value), $datatype);
		} elseif ($change_table_field[$fieldname] != '') {//Added to handle special cases
			$value = $change_table_field[$fieldname] . $this->getAdvComparator($comparator, $value, $datatype);
		} elseif ($change_table_field[$tablename . "." . $fieldname] != '') {//Added to handle special cases
			$tmp_value = '';
			if ((($comparator == 'e' || $comparator == 's' || $comparator == 'c') && trim($value) == '') || (($comparator == 'n' || $comparator == 'k') && trim($value) != '')) {
				$tmp_value = $change_table_field[$tablename . "." . $fieldname] . ' IS NULL or ';
			}
			$value = $tmp_value . $change_table_field[$tablename . "." . $fieldname] . $this->getAdvComparator($comparator, $value, $datatype);
		} elseif (($fieldname == "crmid" && $tablename != 'vtiger_crmentity') || $fieldname == "parent_id" || $fieldname == 'parentid') {
			//For crmentity.crmid the control should not come here. This is only to get the related to modules
			$value = $this->getSalesRelatedName($comparator, $value, $datatype, $tablename, $fieldname);
		} else {
			//For checkbox type values, we have to convert yes/no as 1/0 to get the values
			$field_uitype = getUItype($this->customviewmodule, $fieldname);
			if ($field_uitype == 56) {
				if (strtolower($value) == 'yes')
					$value = 1;
				elseif (strtolower($value) == 'no')
					$value = 0;
			} else if (is_uitype($field_uitype, '_picklist_')) { /* Fix for tickets 4465 and 4629 */
				// Get all the keys for the for the Picklist value
				$mod_keys = array_keys($mod_strings, $value);

				// Iterate on the keys, to get the first key which doesn't start with LBL_  (assuming it is not used in PickList)
				foreach ($mod_keys as $mod_idx => $mod_key) {
					$stridx = strpos($mod_key, 'LBL_');
					// Use strict type comparision, refer strpos for more details
					if ($stridx !== 0) {
						$value = $mod_key;
						break;
					}
				}
			}
			//added to fix the ticket
			if ($this->customviewmodule == "Calendar" && ($fieldname == "status" || $fieldname == "taskstatus" || $fieldname == "eventstatus")) {
				if (getFieldVisibilityPermission("Calendar", $current_user->id, 'taskstatus') == '0') {
					$value = " (case when (vtiger_activity.status not like '') then vtiger_activity.status else vtiger_activity.eventstatus end)" . $this->getAdvComparator($comparator, $value, $datatype);
				}
				else
					$value = " vtiger_activity.eventstatus " . $this->getAdvComparator($comparator, $value, $datatype);
			} elseif ($comparator == 'e' && (trim($value) == "NULL" || trim($value) == '')) {
				$value = '(' . $tablename . "." . $fieldname . ' IS NULL OR ' . $tablename . "." . $fieldname . ' = \'\')';
			} else {
				$value = $tablename . "." . $fieldname . $this->getAdvComparator($comparator, $value, $datatype);
			}
			//end
		}
		return $value;
	}

	/** to get the related name for the given module
	 * @param $comparator :: type string,
	 * @param $value :: type string,
	 * @param $datatype :: type string,
	 * @returns  $value :: string
	 */
	function getSalesRelatedName($comparator, $value, $datatype, $tablename, $fieldname) {
		global $log;
		$log->info("in getSalesRelatedName " . $comparator . "==" . $value . "==" . $datatype . "==" . $tablename . "==" . $fieldname);
		global $adb;

		$adv_chk_value = $value;
		$value = '(';
		$sql = "select distinct(setype) from vtiger_crmentity c INNER JOIN " . $adb->sql_escape_string($tablename) . " t ON t." . $adb->sql_escape_string($fieldname) . " = c.crmid";
		$res = $adb->pquery($sql, array());
		for ($s = 0; $s < $adb->num_rows($res); $s++) {
			$modulename = $adb->query_result($res, $s, "setype");
			if ($modulename == 'Vendors') {
				continue;
			}
			if ($s != 0)
				$value .= ' or ';
			if ($modulename == 'Accounts') {
				//By Pavani : Related to problem in calender, Ticket: 4284 and 4675
				if (($comparator == 'e' || $comparator == 's' || $comparator == 'c') && trim($adv_chk_value) == '') {
					if ($tablename == 'vtiger_seactivityrel' && $fieldname == 'crmid') {
						$value .= 'vtiger_account2.accountname IS NULL or ';
					} else {
						$value .= 'vtiger_account.accountname IS NULL or ';
					}
				}
				if ($tablename == 'vtiger_seactivityrel' && $fieldname == 'crmid') {
					$value .= 'vtiger_account2.accountname';
				} else {
					$value .= 'vtiger_account.accountname';
				}
			}
			if ($modulename == 'Leads') {
				$concatSql = getSqlForNameInDisplayFormat(array('lastname' => 'vtiger_leaddetails.lastname', 'firstname' => 'vtiger_leaddetails.firstname'), 'Leads');
				if (($comparator == 'e' || $comparator == 's' || $comparator == 'c') && trim($adv_chk_value) == '') {
					$value .= " $concatSql IS NULL or ";
				}
				$value .= " $concatSql";
			}
			if ($modulename == 'Potentials') {
				if (($comparator == 'e' || $comparator == 's' || $comparator == 'c') && trim($adv_chk_value) == '') {

					$value .= ' vtiger_potential.potentialname IS NULL or ';
				}
				$value .= ' vtiger_potential.potentialname';
			}
			if ($modulename == 'Products') {
				if (($comparator == 'e' || $comparator == 's' || $comparator == 'c') && trim($adv_chk_value) == '') {
					$value .= ' vtiger_products.productname IS NULL or ';
				}
				$value .= ' vtiger_products.productname';
			}
			if ($modulename == 'Invoice') {
				if (($comparator == 'e' || $comparator == 's' || $comparator == 'c') && trim($adv_chk_value) == '') {
					$value .= ' vtiger_invoice.subject IS NULL or ';
				}
				$value .= ' vtiger_invoice.subject';
			}
			if ($modulename == 'PurchaseOrder') {
				if (($comparator == 'e' || $comparator == 's' || $comparator == 'c') && trim($adv_chk_value) == '') {
					$value .= ' vtiger_purchaseorder.subject IS NULL or ';
				}
				$value .= ' vtiger_purchaseorder.subject';
			}
			if ($modulename == 'SalesOrder') {
				if (($comparator == 'e' || $comparator == 's' || $comparator == 'c') && trim($adv_chk_value) == '') {
					$value .= ' vtiger_salesorder.subject IS NULL or ';
				}
				$value .= ' vtiger_salesorder.subject';
			}
			if ($modulename == 'Quotes') {

				if (($comparator == 'e' || $comparator == 's' || $comparator == 'c') && trim($adv_chk_value) == '') {
					$value .= ' vtiger_quotes.subject IS NULL or ';
				}
				$value .= ' vtiger_quotes.subject';
			}
			if ($modulename == 'Contacts') {
				$concatSql = getSqlForNameInDisplayFormat(array('lastname' => 'vtiger_contactdetails.lastname', 'firstname' => 'vtiger_contactdetails.firstname'), 'Contacts');
				if (($comparator == 'e' || $comparator == 's' || $comparator == 'c') && trim($adv_chk_value) == '') {
					$value .= " $concatSql IS NULL or ";
				}
				$value .= " $concatSql";
			}
			if ($modulename == 'HelpDesk') {
				if (($comparator == 'e' || $comparator == 's' || $comparator == 'c') && trim($adv_chk_value) == '') {
					$value .= ' vtiger_troubletickets.title IS NULL or ';
				}
				$value .= ' vtiger_troubletickets.title';
			}
			if ($modulename == 'Campaigns') {
				if (($comparator == 'e' || $comparator == 's' || $comparator == 'c') && trim($adv_chk_value) == '') {
					$value .= ' vtiger_campaign.campaignname IS NULL or ';
				}
				$value .= ' vtiger_campaign.campaignname';
			}

			$value .= $this->getAdvComparator($comparator, $adv_chk_value, $datatype);
		}
		$value .= ")";
		$log->info("in getSalesRelatedName " . $comparator . "==" . $value . "==" . $datatype . "==" . $tablename . "==" . $fieldname);
		return $value;
	}

	/** to get the comparator value for the given comparator and value
	 * @param $comparator :: type string
	 * @param $value :: type string
	 * @returns  $rtvalue in the format $comparator $value
	 */
	function getAdvComparator($comparator, $value, $datatype = '') {

		global $adb, $default_charset;
		$value = html_entity_decode(trim($value), ENT_QUOTES, $default_charset);
		$value = $adb->sql_escape_string($value);

		if ($comparator == "e") {
			if (trim($value) == "NULL") {
				$rtvalue = " is NULL";
			} elseif (trim($value) != "") {
				$rtvalue = " = " . $adb->quote($value);
			} elseif (trim($value) == "" && ($datatype == "V" || $datatype == "E")) {
				$rtvalue = " = " . $adb->quote($value);
			} else {
				$rtvalue = " is NULL";
			}
		}
		if ($comparator == "n") {
			if (trim($value) == "NULL") {
				$rtvalue = " is NOT NULL";
			} elseif (trim($value) != "") {
				$rtvalue = " <> " . $adb->quote($value);
			} elseif (trim($value) == "" && $datatype == "V") {
				$rtvalue = " <> " . $adb->quote($value);
			} elseif (trim($value) == "" && $datatype == "E") {
				$rtvalue = " <> " . $adb->quote($value);
			} else {
				$rtvalue = " is NOT NULL";
			}
		}
		if ($comparator == "s") {
			if (trim($value) == "" && ($datatype == "V" || $datatype == "E")) {
				$rtvalue = " like '" . formatForSqlLike($value, 3) . "'";
			} else {
				$rtvalue = " like '" . formatForSqlLike($value, 2) . "'";
			}
		}
		if ($comparator == "ew") {
			if (trim($value) == "" && ($datatype == "V" || $datatype == "E")) {
				$rtvalue = " like '" . formatForSqlLike($value, 3) . "'";
			} else {
				$rtvalue = " like '" . formatForSqlLike($value, 1) . "'";
			}
		}
		if ($comparator == "c") {
			if (trim($value) == "" && ($datatype == "V" || $datatype == "E")) {
				$rtvalue = " like '" . formatForSqlLike($value, 3) . "'";
			} else {
				$rtvalue = " like '" . formatForSqlLike($value) . "'";
			}
		}
		if ($comparator == "k") {
			if (trim($value) == "" && ($datatype == "V" || $datatype == "E")) {
				$rtvalue = " not like ''";
			} else {
				$rtvalue = " not like '" . formatForSqlLike($value) . "'";
			}
		}
		if ($comparator == "l") {
			$rtvalue = " < " . $adb->quote($value);
		}
		if ($comparator == "g") {
			$rtvalue = " > " . $adb->quote($value);
		}
		if ($comparator == "m") {
			$rtvalue = " <= " . $adb->quote($value);
		}
		if ($comparator == "h") {
			$rtvalue = " >= " . $adb->quote($value);
		}
		if ($comparator == "b") {
			$rtvalue = " < " . $adb->quote($value);
		}
		if ($comparator == "a") {
			$rtvalue = " > " . $adb->quote($value);
		}

		return $rtvalue;
	}

	/** to get the date value for the given type
	 * @param $type :: type string
	 * @returns  $datevalue array in the following format
	 * $datevalue = Array(0=>$startdate,1=>$enddate)
	 */
	function getDateforStdFilterBytype($type) {
		$currentUserModel = Users_Record_Model::getCurrentUserModel();
		$userPeferredDayOfTheWeek = $currentUserModel->get('dayoftheweek');
		$date = DateTimeField::convertToUserTimeZone(date('Y-m-d H:i:s'));
		$d = $date->format('d');
		$m = $date->format('m');
		$y = $date->format('Y');

		$thisyear = $y;
		$today = date("Y-m-d", mktime(0, 0, 0, $m, $d, $y));
		$todayName =  date('l', strtotime($today));

		$tomorrow = date("Y-m-d", mktime(0, 0, 0, $m, $d + 1, $y));
		$yesterday = date("Y-m-d", mktime(0, 0, 0, $m, $d - 1, $y));

		$currentmonth0 = date("Y-m-d", mktime(0, 0, 0, $m, "01", $y));
		$currentmonth1 = $date->format("Y-m-t");
		$lastmonth0 = date("Y-m-d", mktime(0, 0, 0, $m - 1, "01", $y));
		$lastmonth1 = date("Y-m-t", mktime(0, 0, 0, $m - 1, "01", $y));
		$nextmonth0 = date("Y-m-d", mktime(0, 0, 0, $m + 1, "01", $y));
		$nextmonth1 = date("Y-m-t", mktime(0, 0, 0, $m + 1, "01", $y));
		// (Last Week) If Today is "Sunday" then "-2 week Sunday" will give before last week Sunday date
		if($todayName == $userPeferredDayOfTheWeek)
			$lastweek0 = date("Y-m-d",strtotime("-1 week $userPeferredDayOfTheWeek"));
		else
			$lastweek0 = date("Y-m-d", strtotime("-2 week $userPeferredDayOfTheWeek"));
		$prvDay = date('l',  strtotime(date('Y-m-d', strtotime('-1 day', strtotime($lastweek0)))));
		$lastweek1 = date("Y-m-d", strtotime("-1 week $prvDay"));

		// (This Week) If Today is "Sunday" then "-1 week Sunday" will give last week Sunday date
		if($todayName == $userPeferredDayOfTheWeek)
			$thisweek0 = date("Y-m-d",strtotime("-0 week $userPeferredDayOfTheWeek"));
		else
			$thisweek0 = date("Y-m-d", strtotime("-1 week $userPeferredDayOfTheWeek"));
		$prvDay = date('l',  strtotime(date('Y-m-d', strtotime('-1 day', strtotime($thisweek0)))));
		$thisweek1 = date("Y-m-d", strtotime("this $prvDay"));

		// (Next Week) If Today is "Sunday" then "this Sunday" will give Today's date
		if($todayName == $userPeferredDayOfTheWeek)
			$nextweek0 = date("Y-m-d",strtotime("+1 week $userPeferredDayOfTheWeek"));
		else
			$nextweek0 = date("Y-m-d", strtotime("this $userPeferredDayOfTheWeek"));
		$prvDay = date('l',  strtotime(date('Y-m-d', strtotime('-1 day', strtotime($nextweek0)))));
		$nextweek1 = date("Y-m-d", strtotime("+1 week $prvDay"));

		$next7days = date("Y-m-d", mktime(0, 0, 0, $m, $d + 6, $y));
		$next30days = date("Y-m-d", mktime(0, 0, 0, $m, $d + 29, $y));
		$next60days = date("Y-m-d", mktime(0, 0, 0, $m, $d + 59, $y));
		$next90days = date("Y-m-d", mktime(0, 0, 0, $m, $d + 89, $y));
		$next120days = date("Y-m-d", mktime(0, 0, 0, $m, $d + 119, $y));

		$last7days = date("Y-m-d", mktime(0, 0, 0, $m, $d - 6, $y));
		$last14days = date("Y-m-d", mktime(0, 0, 0, $m, $d - 13, $y));
		$last30days = date("Y-m-d", mktime(0, 0, 0, $m, $d - 29, $y));
		$last60days = date("Y-m-d", mktime(0, 0, 0, $m, $d - 59, $y));
		$last90days = date("Y-m-d", mktime(0, 0, 0, $m, $d - 89, $y));
		$last120days = date("Y-m-d", mktime(0, 0, 0, $m, $d - 119, $y));

		$currentFY0 = date("Y-m-d", mktime(0, 0, 0, "01", "01", $y));
		$currentFY1 = date("Y-m-t", mktime(0, 0, 0, "12", "31", $y));
		$lastFY0 = date("Y-m-d", mktime(0, 0, 0, "01", "01", $y - 1));
		$lastFY1 = date("Y-m-t", mktime(0, 0, 0, "12", "31", $y - 1));
		$nextFY0 = date("Y-m-d", mktime(0, 0, 0, "01", "01", $y + 1));
		$nextFY1 = date("Y-m-t", mktime(0, 0, 0, "12", "31", $y + 1));

		if ($m <= 3) {
			$cFq = date("Y-m-d", mktime(0, 0, 0, "01", "01", $y));
			$cFq1 = date("Y-m-d", mktime(0, 0, 0, "03", "31", $y));
			$nFq = date("Y-m-d", mktime(0, 0, 0, "04", "01", $y));
			$nFq1 = date("Y-m-d", mktime(0, 0, 0, "06", "30", $y));
			$pFq = date("Y-m-d", mktime(0, 0, 0, "10", "01", $y - 1));
			$pFq1 = date("Y-m-d", mktime(0, 0, 0, "12", "31", $y - 1));
		} else if ($m > 3 and $m <= 6) {
			$pFq = date("Y-m-d", mktime(0, 0, 0, "01", "01", $y));
			$pFq1 = date("Y-m-d", mktime(0, 0, 0, "03", "31", $y));
			$cFq = date("Y-m-d", mktime(0, 0, 0, "04", "01", $y));
			$cFq1 = date("Y-m-d", mktime(0, 0, 0, "06", "30", $y));
			$nFq = date("Y-m-d", mktime(0, 0, 0, "07", "01", $y));
			$nFq1 = date("Y-m-d", mktime(0, 0, 0, "9", "30", $y));
		} else if ($m > 6 and $m <= 9) {
			$pFq = date("Y-m-d", mktime(0, 0, 0, "04", "01", $y));
			$pFq1 = date("Y-m-d", mktime(0, 0, 0, "06", "30", $y));
			$cFq = date("Y-m-d", mktime(0, 0, 0, "07", "01", $y));
			$cFq1 = date("Y-m-d", mktime(0, 0, 0, "9", "30", $y));
			$nFq = date("Y-m-d", mktime(0, 0, 0, "10", "01", $y));
			$nFq1 = date("Y-m-d", mktime(0, 0, 0, "12", "31", $y));
		} else {
			$nFq = date("Y-m-d", mktime(0, 0, 0, "01", "01", $y + 1));
			$nFq1 = date("Y-m-d", mktime(0, 0, 0, "03", "31", $y + 1));
			$pFq = date("Y-m-d", mktime(0, 0, 0, "07", "01", $y));
			$pFq1 = date("Y-m-d", mktime(0, 0, 0, "09", "30", $y));
			$cFq = date("Y-m-d", mktime(0, 0, 0, "10", "01", $y));
			$cFq1 = date("Y-m-d", mktime(0, 0, 0, "12", "31", $y));
		}

		if ($type == "today") {

			$datevalue[0] = $today;
			$datevalue[1] = $today;
		} elseif ($type == "yesterday") {

			$datevalue[0] = $yesterday;
			$datevalue[1] = $yesterday;
		} elseif ($type == "tomorrow") {

			$datevalue[0] = $tomorrow;
			$datevalue[1] = $tomorrow;
		} elseif ($type == "thisweek") {

			$datevalue[0] = $thisweek0;
			$datevalue[1] = $thisweek1;
		} elseif ($type == "lastweek") {

			$datevalue[0] = $lastweek0;
			$datevalue[1] = $lastweek1;
		} elseif ($type == "nextweek") {

			$datevalue[0] = $nextweek0;
			$datevalue[1] = $nextweek1;
		} elseif ($type == "thismonth") {

			$datevalue[0] = $currentmonth0;
			$datevalue[1] = $currentmonth1;
		} elseif ($type == "lastmonth") {

			$datevalue[0] = $lastmonth0;
			$datevalue[1] = $lastmonth1;
		} elseif ($type == "nextmonth") {

			$datevalue[0] = $nextmonth0;
			$datevalue[1] = $nextmonth1;
		} elseif ($type == "next7days") {

			$datevalue[0] = $today;
			$datevalue[1] = $next7days;
		} elseif ($type == "next30days") {

			$datevalue[0] = $today;
			$datevalue[1] = $next30days;
		} elseif ($type == "next60days") {

			$datevalue[0] = $today;
			$datevalue[1] = $next60days;
		} elseif ($type == "next90days") {

			$datevalue[0] = $today;
			$datevalue[1] = $next90days;
		} elseif ($type == "next120days") {

			$datevalue[0] = $today;
			$datevalue[1] = $next120days;
		} elseif ($type == "last7days") {

			$datevalue[0] = $last7days;
			$datevalue[1] = $today;
		} elseif ($type == "last14days") {
			$datevalue[0] = $last14days;
			$datevalue[1] = $today;
		} elseif ($type == "last30days") {

			$datevalue[0] = $last30days;
			$datevalue[1] = $today;
		} elseif ($type == "last60days") {

			$datevalue[0] = $last60days;
			$datevalue[1] = $today;
		} else if ($type == "last90days") {

			$datevalue[0] = $last90days;
			$datevalue[1] = $today;
		} elseif ($type == "last120days") {

			$datevalue[0] = $last120days;
			$datevalue[1] = $today;
		} elseif ($type == "thisfy") {

			$datevalue[0] = $currentFY0;
			$datevalue[1] = $currentFY1;
		} elseif ($type == "prevfy") {

			$datevalue[0] = $lastFY0;
			$datevalue[1] = $lastFY1;
		} elseif ($type == "nextfy") {

			$datevalue[0] = $nextFY0;
			$datevalue[1] = $nextFY1;
		} elseif ($type == "nextfq") {

			$datevalue[0] = $nFq;
			$datevalue[1] = $nFq1;
		} elseif ($type == "prevfq") {

			$datevalue[0] = $pFq;
			$datevalue[1] = $pFq1;
		} elseif ($type == "thisfq") {
			$datevalue[0] = $cFq;
			$datevalue[1] = $cFq1;
		} else {
			$datevalue[0] = "";
			$datevalue[1] = "";
		}

		return $datevalue;
	}

	/** to get the customview query for the given customview
	 * @param $viewid (custom view id):: type Integer
	 * @param $listquery (List View Query):: type string
	 * @param $module (Module Name):: type string
	 * @returns  $query
	 */
	//CHANGE : TO IMPROVE PERFORMANCE
	function getModifiedCvListQuery($viewid, $listquery, $module) {
		if ($viewid != "" && $listquery != "") {

			$listviewquery = substr($listquery, strpos($listquery, 'FROM'), strlen($listquery));
			if ($module == "Calendar" || $module == "Emails") {
				$query = "select " . $this->getCvColumnListSQL($viewid) . ", vtiger_activity.activityid, vtiger_activity.activitytype as type, vtiger_activity.priority, case when (vtiger_activity.status not like '') then vtiger_activity.status else vtiger_activity.eventstatus end as status, vtiger_crmentity.crmid,vtiger_contactdetails.contactid " . $listviewquery;
				if ($module == "Calendar")
					$query = str_replace('vtiger_seactivityrel.crmid,', '', $query);
			}else if ($module == "Documents") {
				$query = "select " . $this->getCvColumnListSQL($viewid) . " ,vtiger_crmentity.crmid,vtiger_notes.* " . $listviewquery;
			} else if ($module == "Products") {
				$query = "select " . $this->getCvColumnListSQL($viewid) . " ,vtiger_crmentity.crmid,vtiger_products.* " . $listviewquery;
			} else if ($module == "Vendors") {
				$query = "select " . $this->getCvColumnListSQL($viewid) . " ,vtiger_crmentity.crmid " . $listviewquery;
			} else if ($module == "PriceBooks") {
				$query = "select " . $this->getCvColumnListSQL($viewid) . " ,vtiger_crmentity.crmid " . $listviewquery;
			} else if ($module == "Faq") {
				$query = "select " . $this->getCvColumnListSQL($viewid) . " ,vtiger_crmentity.crmid " . $listviewquery;
			} else if ($module == "Potentials" || $module == "Contacts") {
				$query = "select " . $this->getCvColumnListSQL($viewid) . " ,vtiger_crmentity.crmid,vtiger_account.accountid " . $listviewquery;
			} else if ($module == "Invoice" || $module == "SalesOrder" || $module == "Quotes") {
				$query = "select " . $this->getCvColumnListSQL($viewid) . " ,vtiger_crmentity.crmid,vtiger_contactdetails.contactid,vtiger_account.accountid " . $listviewquery;
			} else if ($module == "PurchaseOrder") {
				$query = "select " . $this->getCvColumnListSQL($viewid) . " ,vtiger_crmentity.crmid,vtiger_contactdetails.contactid " . $listviewquery;
			} else {
				$query = "select " . $this->getCvColumnListSQL($viewid) . " ,vtiger_crmentity.crmid " . $listviewquery;
			}
			$stdfiltersql = $this->getCVStdFilterSQL($viewid);
			$advfiltersql = $this->getCVAdvFilterSQL($viewid);
			if (isset($stdfiltersql) && $stdfiltersql != '') {
				$query .= ' and ' . $stdfiltersql;
			}
			if (isset($advfiltersql) && $advfiltersql != '') {
				$query .= ' and ' . $advfiltersql;
			}
		}
		return $query;
	}

	/** to get the Key Metrics for the home page query for the given customview  to find the no of records
	 * @param $viewid (custom view id):: type Integer
	 * @param $listquery (List View Query):: type string
	 * @param $module (Module Name):: type string
	 * @returns  $query
	 */
	function getMetricsCvListQuery($viewid, $listquery, $module) {
		if ($viewid != "" && $listquery != "") {
			$listviewquery = substr($listquery, strpos($listquery, 'FROM'), strlen($listquery));

			$query = "select count(*) AS count " . $listviewquery;

			$stdfiltersql = $this->getCVStdFilterSQL($viewid);
			$advfiltersql = $this->getCVAdvFilterSQL($viewid);
			if (isset($stdfiltersql) && $stdfiltersql != '') {
				$query .= ' and ' . $stdfiltersql;
			}
			if (isset($advfiltersql) && $advfiltersql != '') {
				$query .= ' and ' . $advfiltersql;
			}
		}

		return $query;
	}

	/** to get the custom action details for the given customview
	 * @param $viewid (custom view id):: type Integer
	 * @returns  $calist array in the following format
	 * $calist = Array ('subject'=>$subject,
	  'module'=>$module,
	  'content'=>$content,
	  'cvid'=>$custom view id)
	 */
	function getCustomActionDetails($cvid) {
		global $adb;

		$sSQL = "select vtiger_customaction.* from vtiger_customaction inner join vtiger_customview on vtiger_customaction.cvid = vtiger_customview.cvid";
		$sSQL .= " where vtiger_customaction.cvid=?";
		$result = $adb->pquery($sSQL, array($cvid));

		while ($carow = $adb->fetch_array($result)) {
			$calist["subject"] = $carow["subject"];
			$calist["module"] = $carow["module"];
			$calist["content"] = $carow["content"];
			$calist["cvid"] = $carow["cvid"];
		}
		return $calist;
	}

	/* This function sets the block information for the given module to the class variable module_list
	 * and return the array
	 */

	function getCustomViewModuleInfo($module) {
		global $adb;
		global $current_language;
		$current_mod_strings = return_specified_module_language($current_language, $module);
		$block_info = Array();
		$modules_list = explode(",", $module);
		if ($module == "Calendar") {
			$module = "Calendar','Events";
			$modules_list = array('Calendar', 'Events');
		}

		// Tabid mapped to the list of block labels to be skipped for that tab.
		$skipBlocksList = array(
			getTabid('HelpDesk') => array('LBL_COMMENTS'),
			getTabid('Faq') => array('LBL_COMMENT_INFORMATION'),
			getTabid('Quotes') => array('LBL_RELATED_PRODUCTS'),
			getTabid('PurchaseOrder') => array('LBL_RELATED_PRODUCTS'),
			getTabid('SalesOrder') => array('LBL_RELATED_PRODUCTS'),
			getTabid('Invoice') => array('LBL_RELATED_PRODUCTS')
		);

		$sql = 'SELECT DISTINCT block, vtiger_field.tabid, name, blocklabel FROM vtiger_field INNER JOIN vtiger_blocks ON vtiger_blocks.blockid = vtiger_field.block INNER JOIN vtiger_tab ON vtiger_tab.tabid = vtiger_field.tabid WHERE vtiger_tab.name IN (' . generateQuestionMarks($modules_list) . ') AND vtiger_field.presence IN (0,2) ORDER BY block';
		$result = $adb->pquery($sql, array($modules_list));
		if ($module == "Calendar','Events")
			$module = "Calendar";

		$pre_block_label = '';
		while ($block_result = $adb->fetch_array($result)) {
			$block_label = $block_result['blocklabel'];
			$tabid = $block_result['tabid'];
			// Skip certain blocks of certain modules
			if (array_key_exists($tabid, $skipBlocksList) && in_array($block_label, $skipBlocksList[$tabid]))
				continue;

			if (trim($block_label) == '') {
				$block_info[$pre_block_label] = $block_info[$pre_block_label] . "," . $block_result['block'];
			} else {
				$lan_block_label = $current_mod_strings[$block_label];
				if (isset($block_info[$lan_block_label]) && $block_info[$lan_block_label] != '') {
					$block_info[$lan_block_label] = $block_info[$lan_block_label] . "," . $block_result['block'];
				} else {
					$block_info[$lan_block_label] = $block_result['block'];
				}
			}
			$pre_block_label = $lan_block_label;
		}
		$this->module_list[$module] = $block_info;
		return $this->module_list;
	}

	/**
	 * Get the userid, status information of this custom view.
	 *
	 * @param Integer $viewid
	 * @return Array
	 */
	protected static $cvStatusAndUser = array();
	function getStatusAndUserid($viewid) {
		global $adb;

		if (!isset(self::$cvStatusAndUser[$viewid]) && ($this->_status === false || $this->_userid === false)) {
			$query = "SELECT status, userid FROM vtiger_customview WHERE cvid=?";
			$result = $adb->pquery($query, array($viewid));
			if ($result && $adb->num_rows($result)) {
				$this->_status = $adb->query_result($result, 0, 'status');
				$this->_userid = $adb->query_result($result, 0, 'userid');
				self::$cvStatusAndUser[$viewid] = array('status' => $this->_status, 'userid' => $this->_userid);
			} else {
				self::$cvStatusAndUser[$viewid] = false;
			}
		}
		return self::$cvStatusAndUser[$viewid];
	}

	//Function to check if the current user is able to see the customView
	function isPermittedCustomView($record_id, $action, $module) {
		global $log, $adb;
		global $current_user;
		$log->debug("Entering isPermittedCustomView($record_id,$action,$module) method....");

		require('user_privileges/user_privileges_' . $current_user->id . '.php');
		$permission = "yes";

		if ($record_id != '') {
			// 指定されているモジュールと対象のcvidが一致しない場合は権限無しとみなす
			$result = $adb->pquery("SELECT * FROM vtiger_customview WHERE cvid=?", array($record_id));
			if ($adb->num_rows($result) > 0) {
				$entityType = $adb->query_result($result, 0, 'entitytype');
				if(!empty($module) && $module != $entityType) {
					return 'no';
				}
			}

			$status_userid_info = $this->getStatusAndUserid($record_id);

			if ($status_userid_info) {
				$status = $status_userid_info['status'];
				$userid = $status_userid_info['userid'];

				if ($status == CV_STATUS_DEFAULT) {
					$log->debug("Entering when status=0");
					if ($action == 'List' || $action == $module . "Ajax" || $action == 'index' || $action == 'Detail' || $action == 'Edit' || $action == 'ListAjax') {
						$permission = "yes";
					} else {
						$permission = "no";
					}
				} elseif ($action != 'ChangeStatus') {
					if ($userid == $current_user->id) {
						$log->debug("Entering when $userid=$current_user->id");
						$permission = "yes";
					} elseif ($status == CV_STATUS_PUBLIC) {
						$log->debug("Entering when status=3");
						if ($action == 'List' || $action == $module . "Ajax" || $action == 'index' || $action == 'Detail' || $action == 'Edit' || $action == 'ListAjax') {
							$permission = "yes";
						} else {
							$permission = "no";
						}
					}
					elseif ($status == CV_STATUS_PRIVATE) {
						$log->debug("Entering when status=1");
						if ($userid == $current_user->id) {
							$permission = "yes";
						}
						$log->debug("Entering when status=1 & action = ListView or $module.Ajax or index");
						if ($this->isPermittedSharedList($record_id))  {
							$permission = "yes";
						} else {
							$sql = "select vtiger_users.id from vtiger_customview inner join vtiger_users where vtiger_customview.cvid = ? and vtiger_customview.userid in (select vtiger_user2role.userid from vtiger_user2role inner join vtiger_users on vtiger_users.id=vtiger_user2role.userid inner join vtiger_role on vtiger_role.roleid=vtiger_user2role.roleid where vtiger_role.parentrole like '%" . $current_user_parent_role_seq . "::%')";
							$result = $adb->pquery($sql, array($record_id));
							$temp_result = array();
							while ($row = $adb->fetchByAssoc($result)) {
								$temp_result[] = $row['id'];
							}
							$user_array = $temp_result;
							if (sizeof($user_array) > 0) {
								if (!in_array($current_user->id, $user_array)) {
									$permission = "no";
								} else {
									$permission = "yes";
								}
							} else {
								$permission = "no";
							}
						}
					} elseif ($status == CV_STATUS_PENDING) {
						$log->debug("Entering when status=2");
						if ($userid == $current_user->id)
							$permission = "yes";
						else {
							/* if($action == 'ListView' || $action == $module."Ajax" || $action == 'index')
							  { */
							$log->debug("Entering when status=1 or status=2 & action = ListView or $module.Ajax or index");

							$sql = 'SELECT * FROM vtiger_customview WHERE vtiger_customview.cvid = ?';

							$params = array($record_id);

							if(!empty($module)) {
								$sql .= ' AND entitytype=?';
								$params[] = $module;
							}

							$userGroups = new GetUserGroups();
							$userGroups->getAllUserGroups($current_user->id);
							$groups = $userGroups->user_groups;
							$userRole = fetchUserRole($current_user->id);
							$parentRoles=getParentRole($userRole);
							$parentRolelist= array();
							foreach($parentRoles as $par_rol_id) {
								array_push($parentRolelist, $par_rol_id);		
							}
							array_push($parentRolelist, $userRole);

							$userPrivilegeModel = Users_Privileges_Model::getCurrentUserPrivilegesModel();
							$userParentRoleSeq = $userPrivilegeModel->get('parent_role_seq');
							$sql .= " AND ( vtiger_customview.userid = ? OR vtiger_customview.status = 0 OR vtiger_customview.status = 3
											OR vtiger_customview.cvid IN (SELECT vtiger_cv2users.cvid FROM vtiger_cv2users WHERE vtiger_cv2users.userid=?)";
							$params[] = $current_user->id;
							$params[] = $current_user->id;

							//下位の役割が作成した全てのリストをチェック
							global $bewithconfig;
							if($bewithconfig['show_subordinate_roles_list']) {
								$sql .= "OR vtiger_customview.userid IN (SELECT vtiger_user2role.userid FROM vtiger_user2role
											INNER JOIN vtiger_users ON vtiger_users.id = vtiger_user2role.userid
											INNER JOIN vtiger_role ON vtiger_role.roleid = vtiger_user2role.roleid
										WHERE vtiger_role.parentrole LIKE '".$userParentRoleSeq."::%') ";
							}
							if(!empty($groups)){
								$sql .= "OR vtiger_customview.cvid IN (SELECT vtiger_cv2group.cvid FROM vtiger_cv2group WHERE vtiger_cv2group.groupid IN (".  generateQuestionMarks($groups)."))";
								$params = array_merge($params,$groups);
							}

							$sql.= "OR vtiger_customview.cvid IN (SELECT vtiger_cv2role.cvid FROM vtiger_cv2role WHERE vtiger_cv2role.roleid =?)";
							$params[] = $userRole;
							if(!empty($parentRolelist)){
								$sql.= "OR vtiger_customview.cvid IN (SELECT vtiger_cv2rs.cvid FROM vtiger_cv2rs WHERE vtiger_cv2rs.rsid IN (". generateQuestionMarks($parentRolelist) ."))";
								$params = array_merge($params,$parentRolelist);
							}

							$sql.= ")";


							$result = $adb->pquery($sql, $params);

							while ($row = $adb->fetchByAssoc($result)) {
								$temp_result[] = $row['cvid'];
							}
							if (sizeof($temp_result) > 0 && in_array($record_id, $temp_result)) {
								$permission = "yes";
							}
							else
								$permission = "no";
							/* }
							  else
							  {
							  $log->debug("Entering when status=1 or 2 & action = Editview or Customview");
							  $permission = "no";
							  } */
						}
					}
					else
						$permission = "yes";
				}
				else {
					$log->debug("Entering else condition............");
					$permission = "no";
				}
			} else {
				$log->debug("Enters when count =0");
				$permission = 'no';
			}
		}
		$log->debug("Permission @@@@@@@@@@@@@@@@@@@@@@@@@@@ : $permission");
		$log->debug("Exiting isPermittedCustomView($record_id,$action,$module) method....");
		return $permission;
	}

	function isPermittedChangeStatus($status) {
		global $current_user, $log;
		global $current_language;
		$custom_strings = return_module_language($current_language, "CustomView");

		$log->debug("Entering isPermittedChangeStatus($status) method..............");
		require('user_privileges/user_privileges_' . $current_user->id . '.php');
		$status_details = Array();
		if ($is_admin) {
			if ($status == CV_STATUS_PENDING) {
				$changed_status = CV_STATUS_PUBLIC;
				$status_label = $custom_strings['LBL_STATUS_PUBLIC_APPROVE'];
			} elseif ($status == CV_STATUS_PUBLIC) {
				$changed_status = CV_STATUS_PENDING;
				$status_label = $custom_strings['LBL_STATUS_PUBLIC_DENY'];
			}
			$status_details = Array('Status' => $status, 'ChangedStatus' => $changed_status, 'Label' => $status_label);
		}
		$log->debug("Exiting isPermittedChangeStatus($status) method..............");
		return $status_details;
	}

	/**
	 * This Function checks if the list is shared.
	 * @return <Boolean> true or false
	 */
	function isPermittedSharedList($record_id) {
		global $adb;
		global $current_user;

		$result = $adb->pquery('SELECT cvid FROM vtiger_cv2users WHERE cvid = ? AND userid = ?', array($record_id, $current_user->id));
		if ($adb->num_rows($result)) {
			return true;
		}

		$result = $adb->pquery('SELECT cvid FROM vtiger_cv2role WHERE cvid = ? AND roleid = ?', array($record_id, $current_user->roleid));
		if ($adb->num_rows($result)) {
			return true;
		}

		$userGroups = new GetUserGroups();
		$userGroups->getAllUserGroups($current_user->id);
		$groups = $userGroups->user_groups;
		if (!empty($groups)) {
			$result = $adb->pquery('SELECT cvid FROM vtiger_cv2group WHERE cvid = ? AND vtiger_cv2group.groupid IN ('.generateQuestionMarks($groups).')', array($record_id, $groups));
			if ($adb->num_rows($result)) {
				return true;
			}
		}
		
		$result = $adb->pquery('SELECT rsid FROM vtiger_cv2rs WHERE cvid = ?', array($record_id));
		if ($adb->num_rows($result)) {
			$rsid = $adb->query_result($result, 0, 'rsid');
			$roleSubordinates = getRoleSubordinates($rsid);
			$roleSubordinateList = array();
			foreach($roleSubordinates as $par_rol_id) {
				array_push($roleSubordinateList, $par_rol_id);		
			}
			array_push($roleSubordinateList, $rsid);
			if (in_array($current_user->roleid, $roleSubordinateList)) {
				return true;
			}
		}

		return false;
	}
}

?>
