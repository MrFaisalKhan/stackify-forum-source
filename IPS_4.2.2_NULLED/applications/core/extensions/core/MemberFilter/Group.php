<?php
/**
 * @brief		Member filter extension: member groups
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @since		20 June 2013
 */

namespace IPS\core\extensions\core\MemberFilter;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * @brief	Member filter: Member group
 */
class _Group
{
	/**
	 * Determine if the filter is available in a given area
	 *
	 * @param	string	$area	Area to check
	 * @return	bool
	 */
	public function availableIn( $area )
	{
		return in_array( $area, array( 'bulkmail', 'group_promotions' ) );
	}

	/**
	 * Get Setting Field
	 *
	 * @param	mixed	$criteria	Value returned from the save() method
	 * @return	array 	Array of form elements
	 */
	public function getSettingField( $criteria )
	{
		/* Get our options */
		$criteria['options'] = array_combine( array_keys( \IPS\Member\Group::groups( TRUE, FALSE ) ), array_map( function( $_group ) { return (string) $_group; }, \IPS\Member\Group::groups( TRUE, FALSE ) ) );

		/* If all *available* options are selected, we want to choose 'all' for consistency on create vs edit */
		$_options = array_keys( $criteria['options'] );

		if( isset( $criteria['disabled_groups'] ) )
		{
			$_options = array_diff( $_options, $criteria['disabled_groups'] );
		}

		if( isset( $criteria['groups'] ) )
		{
			if( !count( array_diff($_options,explode( ',', $criteria['groups'] ) ) ) )
			{
				$criteria['groups'] = 'all';
			}
		}
				
		return array(
			new \IPS\Helpers\Form\Select( 'bmf_members_groups', ( isset( $criteria['groups'] ) AND $criteria['groups'] != 'all' ) ? explode( ',', $criteria['groups'] ) : 'all', FALSE, array( 
				'options'		=> $criteria['options'],
				'multiple'		=> TRUE, 
				'unlimited'		=> 'all', 
				'unlimitedLang'	=> 'all_groups' 
			) )
		);
	}
	
	/**
	 * Save the filter data
	 *
	 * @param	array	$post	Form values
	 * @return	mixed			False, or an array of data to use later when filtering the members
	 * @throws \LogicException
	 */
	public function save( $post )
	{
		return ( empty( $post['bmf_members_groups'] ) OR $post['bmf_members_groups'] == 'all' ) ? array( 'groups' => NULL ) : array( 'groups' => implode( ',', $post['bmf_members_groups'] ) );
	}
	
	/**
	 * Get where clause to add to the member retrieval database query
	 *
	 * @param	mixed				$data	The array returned from the save() method
	 * @return	string|array|NULL	Where clause
	 */
	public function getQueryWhereClause( $data )
	{
		if ( $data['groups'] )
		{
			$_groups	= explode( ',', $data['groups'] );
			$_set		= array();

			foreach( $_groups as $_group )
			{
				$_set[]	= "FIND_IN_SET(" . $_group . ",mgroup_others)";
			}

			if( count($_set) )
			{
				return "( member_group_id IN(" . $data['groups'] . ") OR " . implode( ' OR ', $_set ) . ' )';
			}
		}

		return NULL;
	}

	/**
	 * Determine if a member matches specified filters
	 *
	 * @note	This is only necessary if availableIn() includes group_promotions
	 * @param	\IPS\Member	$member		Member object to check
	 * @param	array 		$filters	Previously defined filters
	 * @return	bool
	 */
	public function matches( \IPS\Member $member, $filters )
	{
		/* If we aren't filtering by this, then any member matches */
		if( !isset( $filters['groups'] ) OR !$filters['groups'] )
		{
			return TRUE;
		}

		$_groups	= explode( ',', $filters['groups'] );

		/* This intentionally checks secondary groups, as SQL filtering also checks secondary groups */
		return (bool) count( array_intersect( $_groups, $member->groups ) );
	}
}