{*+**********************************************************************************
* The contents of this file are subject to the vtiger CRM Public License Version 1.1
* ("License"); You may not use this file except in compliance with the License
* The Original Code is: vtiger CRM Open Source
* The Initial Developer of the Original Code is vtiger.
* Portions created by vtiger are Copyright (C) vtiger.
* All Rights Reserved.
************************************************************************************}

<br>
<center>
	<footer class="noprint">
		<div class="vtFooter">
			<p>
				{vtranslate('POWEREDBY')} {$VTIGER_VERSION}&nbsp;
				&copy; 2004 - {date('Y')}&nbsp;
				<a href="https://f-revocrm.jp" target="_blank">f-revocrm.jp</a>
				&nbsp;|&nbsp;
				{* <a href="#" onclick="window.open('copyright.html', 'copyright', 'height=115,width=575').moveTo(210, 620)">{vtranslate('LBL_READ_LICENSE')}</a>
				&nbsp;|&nbsp; *}
				<a href="https://f-revocrm.jp/privacy" target="_blank">{vtranslate('LBL_PRIVACY_POLICY')}</a>
			</p>
		</div>
	</footer>
</center>
<div id="js_strings" class="hide noprint">{Zend_Json::encode($LANGUAGE_STRINGS)}</div>
{include file='JSResources.tpl'|@vtemplate_path}
</div>
