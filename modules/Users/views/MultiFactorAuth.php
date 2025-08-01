<?php
/*+**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.1
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 *************************************************************************************/

class Users_MultiFactorAuth_View extends Vtiger_View_Controller {

    function loginRequired() {
        return false;
    }

    function checkPermission(Vtiger_Request $request) {
        return true;
    }

    function preProcess(Vtiger_Request $request, $display = true) {
        $viewer = $this->getViewer($request);
        $viewer->assign('PAGETITLE', $this->getPageTitle($request));
        $viewer->assign('SCRIPTS', $this->getHeaderScripts($request));
        $viewer->assign('STYLES', $this->getHeaderCss($request));
        $viewer->assign('MODULE', $request->getModule());
        $viewer->assign('VIEW', $request->get('view'));
        $viewer->assign('LANGUAGE_STRINGS', array());
        if ($display) {
            $this->preProcessDisplay($request);
        }
    }

    public function process(Vtiger_Request $request)
    {
        $viewer = $this->getViewer($request);
        $moduleName = $request->getModule(false);
        $userid = $_SESSION['multi_factor_auth_userid'];
        $currentUser = Users_Record_Model::getInstanceById($userid, 'Users');
        $viewer->assign('USERID',$userid);

        $userCredentialData = $currentUser->getUserCredential();
        $forceMultiFactorAuth = Settings_Parameters_Record_Model::getParameterValue("FORCE_MULTI_FACTOR_AUTH", "false");
            
        // 多要素が強制されていて、ユーザー認証情報が存在しない場合は、設定ページへリダイレクト
        if( $forceMultiFactorAuth == "true" && empty($userCredentialData)) {
            header('Location: index.php?module=Users&view=ForceAddMultiFactorAuthentication&step=step1');
            exit;
        }
        else
        {
            // 複数のデータを持っていてTypeがTOTPとPassKeyの入っている場合はPassKeyの認証ページに遷移
            $passkeyData = array_filter($userCredentialData, function($data) {
                return $data['type'] === 'passkey';
            });
            if( $passkeyData !== false && count($passkeyData) > 0 ) {
                $viewer->assign('SETPASSKEY', true);
            }
            $totpData = array_filter($userCredentialData, function($data) {
                return $data['type'] === 'totp';
            });
            if( $totpData !== false && count($totpData) > 0 ) {
                $viewer->assign('SETTOTP', true);
            }
        }
        

        $companyDetails = Vtiger_CompanyDetails_Model::getInstanceById();
        $companyLogo = $companyDetails->getLogo();
        $viewer->assign('COMPANY_LOGO',$companyLogo);
        $viewer->assign('LANGUAGE_STRINGS', Vtiger_Language_Handler::export('Users', 'jsLanguageStrings'));

        // 2要素認証のページを表示
        $viewer->view('MultiFactorAuth.tpl', $moduleName);
    }

     function postProcess(Vtiger_Request $request) {
        $moduleName = $request->getModule();
        $viewer = $this->getViewer($request);
        $viewer->view('Footer.tpl', $moduleName);
    }
}