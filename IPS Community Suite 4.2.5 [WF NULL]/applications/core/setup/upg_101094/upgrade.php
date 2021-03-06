<?php
/**
 * @brief		4.1.19.1 Upgrade Code
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @since		17 Mar 2017
 */

namespace IPS\core\setup\upg_101094;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * 4.1.19.1 Upgrade Code
 */
class _Upgrade
{
	/**
	 * Step 1
	 * Fix announcements
	 *
	 * @return	array	If returns TRUE, upgrader will proceed to next step. If it returns any other value, it will set this as the value of the 'extra' GET parameter and rerun this step (useful for loops)
	 */
	public function step1()
	{
		if ( \IPS\Db::i()->select( 'COUNT(*)', 'core_announcements', 'announce_active=1' )->first() )
		{
			\IPS\Db::i()->replace( 'core_sys_conf_settings', array( 'conf_value' => 1, 'conf_key' => 'announcements_exist', 'conf_app' => 'core', 'conf_default' => 0 ) );
			unset( \IPS\Data\Store::i()->settings );
		}

		return true;
	}
}