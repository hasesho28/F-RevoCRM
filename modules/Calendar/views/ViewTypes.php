<?php
/*+***********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 *************************************************************************************/

class Calendar_ViewTypes_View extends Vtiger_IndexAjax_View {

	function __construct() {
		parent::__construct();
		$this->exposeMethod('getViewTypes');
		$this->exposeMethod('getSharedUsersList');
	}

	function getViewTypes(Vtiger_Request $request) {
		$viewer = $this->getViewer($request);
		$moduleName = $request->getModule();
		$currentUser = Users_Record_Model::getCurrentUserModel();
		$calendarViews = Calendar_Module_Model::getCalendarViewTypes($currentUser->id);
		$allViewTypes = Calendar_Module_Model::getCalendarViewTypesToAdd($currentUser->id);

		$viewer->assign('MODULE', $moduleName);
		$viewer->assign('VIEWTYPES', $calendarViews);
		$viewer->assign('ADDVIEWS', $allViewTypes);
		$viewer->view('CalendarViewTypes.tpl', $moduleName);
	}

	/**
	 * Function to get Shared Users
	 * @param Vtiger_Request $request
	 */
	function getSharedUsersList(Vtiger_Request $request){
		$viewer = $this->getViewer($request);
		$currentUser = Users_Record_Model::getCurrentUserModel();


		$moduleName = $request->getModule();
		$sharedUsers = Calendar_Module_Model::getSharedUsersOfCurrentUser($currentUser->id);
		$sharedGroups = Calendar_Module_Model::getSharedCalendarGroupsList($currentUser->id);
		$sharedUsersInfo = Calendar_Module_Model::getSharedUsersInfoOfCurrentUser($currentUser->id);

		$allRoles = Settings_Roles_Record_Model::getAll();
		$allGroups = Settings_Groups_Record_Model::getAll();

		$viewer->assign('MODULE', $moduleName);
		$viewer->assign('SHAREDUSERS', $sharedUsers);
		$viewer->assign('SHAREDGROUPS', $sharedGroups);
		$viewer->assign('SHAREDUSERS_INFO', $sharedUsersInfo);
		$viewer->assign('CURRENTUSER_MODEL',$currentUser);
		$viewer->assign('ALL_ROLES',$allRoles);
		$viewer->assign('ALL_GROUPS',$allGroups);
		$viewer->assign('SHARED_CALENDAR_TODO_VIEW', $currentUser->getSharedCalendarTodoView());
		$viewer->view('CalendarSharedUsers.tpl', $moduleName);
	}
}
