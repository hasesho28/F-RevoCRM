{*+**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.1
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is: vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 ************************************************************************************}
{* modules/Vtiger/views/Detail.php *}

{* START YOUR IMPLEMENTATION FROM BELOW. Use {debug} for information *}
{strip}
	{assign var=NAME_FIELDS value=array('last_name', 'first_name')}
	{if $MODULE_MODEL}
		{assign var=NAME_FIELDS value=$MODULE_MODEL->getNameFields()}
	{/if}
    <form id="detailView" data-name-fields='{ZEND_JSON::encode($NAME_FIELDS)}' method="POST">
        {include file='DetailViewBlockView.tpl'|@vtemplate_path:$MODULE_NAME RECORD_STRUCTURE=$RECORD_STRUCTURE MODULE_NAME=$MODULE_NAME}
        {include file='DetailViewUserCredentialBlockView.tpl'|@vtemplate_path:$MODULE_NAME RECORD_STRUCTURE=$RECORD_STRUCTURE MODULE_NAME=$MODULE_NAME USER_MULTI_FACTOR_CREDENTIAL_LIST=$USER_MULTI_FACTOR_CREDENTIAL_LIST RECORDID=$RECORDID}
    </form>
{/strip}