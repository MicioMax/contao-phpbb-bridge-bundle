<?php
/*
 * This file is part of contao-phpbbBridge
 * 
 * Copyright (c) CTS GmbH
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 */

$GLOBALS['TL_DCA']['tl_page']['palettes']['phpbb_forum'] = '{title_legend},title,type;{phpbb_legend},phpbb_alias,phpbb_path,phpbb_default_groups;{layout_legend:hide},includeLayout;cssClass,phpbb_dynamic_layout;{tabnav_legend:hide},tabindex,accesskey;{publish_legend},published';

$GLOBALS['TL_DCA']['tl_page']['config']['onsubmit_callback'][] = array('tl_page_phpbbforum', 'updateConfig');
$GLOBALS['TL_DCA']['tl_page']['config']['onsubmit_callback'][] = array('tl_page_phpbbforum', 'generateForumLayout');

// @todo add translations and label texts
$GLOBALS['TL_DCA']['tl_page']['fields']['phpbb_alias'] = array
(
    'label'                   => &$GLOBALS['TL_LANG']['tl_page']['phpbb_alias'],
    'exclude'                 => true,
    'inputType'               => 'text',
    'search'                  => false,
    'eval'                    => array('rgxp'=>'folderalias', 'doNotCopy'=>true, 'maxlength'=>128, 'tl_class'=>'w50', 'mandatory' => true),
    'sql'                     => "varchar(128) COLLATE utf8_bin NOT NULL default ''"
);
// @todo add translations and label texts
$GLOBALS['TL_DCA']['tl_page']['fields']['phpbb_path'] = array
(
    'label'                   => &$GLOBALS['TL_LANG']['tl_page']['phpbb_path'],
    'exclude'                 => true,
    'inputType'               => 'text',
    'search'                  => false,
    'eval'                    => array('rgxp'=>'folderalias', 'doNotCopy'=>true, 'maxlength'=>256, 'tl_class'=>'w50', 'mandatory' => true),
    'sql'                     => "varchar(256) COLLATE utf8_bin NOT NULL default ''",
    'save_callback' => array
    (
        array('tl_page_phpbbforum', 'generatePhpbbLink')
    ),
);
// @todo add translations and label texts
$GLOBALS['TL_DCA']['tl_page']['fields']['phpbb_dynamic_layout'] = array
(
    'label'                   => &$GLOBALS['TL_LANG']['tl_page']['phpbb_dynamic_layout'],
    'exclude'                 => true,
    'inputType'               => 'checkbox',
    'eval'                    => array('tl_class'=>'w50 m12'),
    'sql'                     => "char(1) NOT NULL default ''"
);

$GLOBALS['TL_DCA']['tl_page']['fields']['phpbb_default_groups'] = array
(
    'label'                   => &$GLOBALS['TL_LANG']['tl_member']['groups'],
    'exclude'                 => true,
    'filter'                  => true,
    'inputType'               => 'checkboxWizard',
    'foreignKey'              => 'tl_member_group.name',
    'eval'                    => array('multiple'=>true, 'feEditable'=>true, 'feGroup'=>'login'),
    'sql'                     => "blob NULL",
    'relation'                => array('type'=>'belongsToMany', 'load'=>'lazy')
);

class tl_page_phpbbforum extends tl_page {

    public function generatePhpbbLink($varValue, DataContainer $dc){

        if(is_link($dc->activeRecord->phpbb_alias) && readlink($dc->activeRecord->phpbb_alias) == $varValue) {
            Message::addInfo("Path to forum already set");
            return $varValue;
        }

        if(is_link($dc->activeRecord->phpbb_alias)  !== false && readlink($dc->activeRecord->phpbb_alias) != $varValue) {
            Message::addInfo("Removing old link");
            unlink($dc->activeRecord->phpbb_alias);
        }

        Message::addInfo("Trying to set Forum Symlink");
        if(file_exists($varValue . "/viewtopic.php")){
            Message::addInfo("Forum found. Setting Link");
            $result = symlink($varValue, $dc->activeRecord->phpbb_alias);
            if($result === true) {
                Message::addInfo("Link Set");
            }

            if(!is_link($dc->activeRecord->phpbb_alias . '/ext/ctsmedia') ||
                readlink($dc->activeRecord->phpbb_alias . '/ext/ctsmedia') != "../../contao/vendor/ctsmedia/contao-phpbb-bridge-bundle/src/Resources/phpBB/ctsmedia" ) {
                Message::addInfo("Setting Vendor Link");
                symlink(TL_ROOT . "/vendor/ctsmedia/contao-phpbb-bridge-bundle/src/Resources/phpBB/ctsmedia", $dc->activeRecord->phpbb_alias . '/ext/ctsmedia');
            }

            Message::addInfo("Please activate the contao extension in the phpbb backend");
        } else {
            //Message::addError("Forum could not be found: ".$varValue . "/viewtopic.php");
            throw new Exception("Forum could not be found: ".$varValue . "/viewtopic.php");
        }

        return $varValue;
    }

    public  function updateConfig(DataContainer $dc) {

        // Return if there is no active record (override all)
        if (!$dc->activeRecord || $dc->activeRecord->type != 'phpbb_forum')
        {
            return;
        }

        Message::addInfo("Updating Config");
        $row = $dc->activeRecord->row();
        $row['skipInternalHook'] = true;
        $url = Controller::generateFrontendUrl($row);
        System::getContainer()->get('phpbb_bridge.connector')->updateConfig(array(
            'contao.forum_pageId' => $dc->activeRecord->id,
            'contao.forum_pageUrl' => Environment::get('url').'/'.$url,
            'contao.url' => Environment::get('url'),
            'contao.load_dynamic_layout' => $dc->activeRecord->phpbb_dynamic_layout,
            'contao.forum_pageAlias' => $dc->activeRecord->phpbb_alias,
            'contao.bridge_is_installed' => true,
        ));
        System::getContainer()->get('phpbb_bridge.connector')->setMandatoryDbConfigValues();
        System::getContainer()->get('phpbb_bridge.connector')->testCookieDomain();
    }


    public function generateForumlayout(DataContainer $dc) {

        // Return if there is no active record (override all)
        if (!$dc->activeRecord || $dc->activeRecord->type != 'phpbb_forum')
        {
            return;
        }

        Message::addInfo("Generating Layout");

        $row = $dc->activeRecord->row();
        $row['skipInternalHook'] = true;
        $url = Controller::generateFrontendUrl($row, null, null, false);

        $frontendRequest = new \Contao\Request();
        $frontendRequest->send(Environment::get('url').'/'.$url);



    }

}