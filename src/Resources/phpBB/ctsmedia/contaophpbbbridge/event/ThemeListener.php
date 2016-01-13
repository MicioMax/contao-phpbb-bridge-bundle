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

namespace ctsmedia\contaophpbbbridge\event;

use ctsmedia\contaophpbbbridge\contao\Connector;
use phpbb\event\data;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


/**
 *
 * @package ctsmedia\contaophpbbbridge\event
 * @author Daniel Schwiperich <d.schwiperich@cts-media.eu>
 */
class ThemeListener implements EventSubscriberInterface
{

    /**
     * @var \phpbb\template\template
     */
    protected $template;

    protected $contaoConnector;

    protected $config = array();

    /**
     * ThemeListener constructor.
     * @param $phpbb_root_path
     * @param \phpbb\template\template $template
     */
    public function __construct($phpbb_root_path , \phpbb\template\twig\twig $template, Connector $contaoConnector, array $config)
    {
        $this->template = $template;
        $this->contaoConnector = $contaoConnector;
        $this->config = $config;

    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return array(
            'core.page_header' => 'injectThemeHeader',
            'core.page_footer' => 'injectThemeFooter'
        );
    }

    /**
     * @param Event $event
     */
    public function injectThemeHeader(data $event) {

        // Overwrite Header
        //$data = $event->get_data();
        //$data['page_header_override'] = true;
        //$data['page_title'] = "xxx";
        //$event->set_data($data);

        //@todo replace with http library (buzz)

        // Load dynamic rendered layout sections (this is optional, otherwise static content will be used)
        if( true === (bool)$this->config['load_dynamic_layout']){
            $jsonResponse = file_get_contents($this->config['forum_pageUrl'].'?format=json');
            $sections = json_decode($jsonResponse);

            if(isset($sections->overall_header)) {
                $this->template->assign_var('CONTAO_LAYOUT_HEADER', $sections->overall_header);
            }
            if(isset($sections->overall_footer)) {
                $this->template->assign_var('CONTAO_LAYOUT_FOOTER', $sections->overall_footer);
            }
        }

    }

    /**
     * @param Event $event
     */
    public function injectThemeFooter(Event $event) {

        if(isset($this->config['body_class'])) {
            $this->template->append_var('BODY_CLASS', $this->config['body_class']);
        }



//        echo "ContaoConnection";
//
//        dump($this->contaoConnector->getForumPageId());
//
//        echo "template";
//
//        dump(get_class($this->template));
//        dump($this->template);
//
//
//
//        echo "event";
//
//        dump($event);

    }


}