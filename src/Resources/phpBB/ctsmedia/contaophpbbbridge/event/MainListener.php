<?php
/*
 * This file is part of contao-phpbb-bridge-bundle
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
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


/**
 * Class MainListener
 * @package ctsmedia\contaophpbbbridge\event
 * @author Daniel Schwiperich <d.schwiperich@cts-media.eu>
 */
class MainListener implements EventSubscriberInterface
{


    /**
     * MainListener constructor.
     * @param Connector $contaoConnector
     * @param array $config
     */
    public function __construct(Connector $contaoConnector, array $config = [])
    {
        $this->contaoConnector = $contaoConnector;
        $this->config = $config;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return array(
            'core.ucp_profile_reg_details_sql_ary'	=> 'updateContaoProfile',
        );
    }

    /**
     * Update contao member if changes are made by the user in phpbb
     *
     * @param data $event
     */
    public function updateContaoProfile(data $event) {

        $data = $event->get_data();

        $hasPasswordChanged = (bool)$data['sql_ary']['user_passchg'];
        $user = $this->contaoConnector->getContaoUser($data['data']['username']);
        
        if($user !== false && isset($user['id'])) {


            $updateUser = array('email' => $data['sql_ary']['user_email'], 'tstamp' => time());
            if($hasPasswordChanged) {
                // This should normaly work for contao. @see Encryption::hash() method
                // If not, it doesn't matter because Contao will ask phpbb if credentials are ok
                $updateUser['password'] = password_hash($data['data']['new_password'], PASSWORD_BCRYPT, ['cost'=>PASSWORD_BCRYPT_DEFAULT_COST]);
            }

            $sql = 'UPDATE tl_member
                    SET ' . $this->contaoConnector->getContaoDbConnection()->sql_build_array('UPDATE', $updateUser) . '
                    WHERE id = ' . $user['id'];

            $this->contaoConnector->getContaoDbConnection()->sql_query($sql);
        }
        
    }



}