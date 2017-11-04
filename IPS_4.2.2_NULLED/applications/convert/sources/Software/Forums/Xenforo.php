<?php

/**
 * @brief		Converter XenForo Class
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @package		Invision Community
 * @subpackage	Converter
 * @since		21 Jan 2015
 */

namespace IPS\convert\Software\Forums;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Xenforo extends \IPS\convert\Software
{
	/**
	 * @brief	Flag to indicate the post data has been fixed during conversion, and we only need to use Legacy Parser
	 */
	public static $contentFixed = TRUE;

	/**
	 * Software Name
	 *
	 * @return	string
	 */
	public static function softwareName()
	{
		/* Child classes must override this method */
		return "XenForo";
	}
	
	/**
	 * Software Key
	 *
	 * @return	string
	 */
	public static function softwareKey()
	{
		/* Child classes must override this method */
		return "xenforo";
	}
	
	/**
	 * Content we can convert from this software. 
	 *
	 * @return	array
	 */
	public static function canConvert()
	{
		return array(
			'convertForumsForums' => array(
				'table'					=> 'xf_node',
				'where'					=> array( \IPS\Db::i()->in( 'node_type_id', array( 'Category', 'Forum', 'LinkForum' ) ) )
			),
			'convertForumsTopics'	=> array(
				'table'					=> 'xf_thread',
				'where'					=> NULL
			),
			'convertForumsPosts'	=> array(
				'table'					=> 'xf_post',
				'where'					=> NULL,
			),
			'convertAttachments'	=> array(
				'table'					=> 'xf_attachment',
				'where'					=> array( "content_type=?", 'post' )
			)
		);
	}

	/**
	 * Requires Parent
	 *
	 * @return	boolean
	 */
	public static function requiresParent()
	{
		return TRUE;
	}
	
	/**
	 * Possible Parent Conversions
	 *
	 * @return	array
	 */
	public static function parents()
	{
		return array( 'core' => array( 'xenforo' ) );
	}

	/**
	 * List of conversion methods that require additional information
	 *
	 * @return	array
	 */
	public static function checkConf()
	{
		return array(
			'convertAttachments', 
			'convertForumsPosts' 
		);
	}
	
	/**
	 * Get More Information
	 *
	 * @param	string	$method	Conversion method
	 * @return	array
	 */
	public function getMoreInfo( $method )
	{
		$return = array();
		switch( $method )
		{
			case 'convertForumsPosts':
				/* Get our reactions to let the admin map them */
				$options		= array();
				$descriptions	= array();
				foreach( new \IPS\Patterns\ActiveRecordIterator( \IPS\Db::i()->select( '*', 'core_reactions' ), 'IPS\Content\Reaction' ) AS $reaction )
				{
					$options[ $reaction->id ]		= $reaction->_icon->url;
					$descriptions[ $reaction->id ]	= \IPS\Member::loggedIn()->language()->addToStack('reaction_title_' . $reaction->id ) . '<br>' . $reaction->_description;
				}

				$return['convertForumsPosts'] = array(
					'rep_like'	=> array(
						'field_class'		=> 'IPS\\Helpers\\Form\\Radio',
						'field_default'		=> NULL,
						'field_required'	=> TRUE,
						'field_extra'		=> array( 'parse' => 'image', 'options' => $options, 'descriptions' => $descriptions ),
						'field_hint'		=> NULL,
						'field_validation'	=> NULL,
					),
				);
				break;

			case 'convertAttachments':
				$return['convertAttachments'] = array(
					'attach_location' => array(
						'field_class'			=> 'IPS\\Helpers\\Form\\Text',
						'field_default'			=> NULL,
						'field_required'		=> TRUE,
						'field_extra'			=> array(),
						'field_hint'			=> \IPS\Member::loggedIn()->language()->addToStack('convert_xf_attach_path'),
						'field_validation'	=> function( $value ) { if ( !@is_dir( $value ) ) { throw new \DomainException( 'path_invalid' ); } },
					)
				);
				break;
		}
		
		return ( isset( $return[ $method ] ) ) ? $return[ $method ] : array();
	}
	
	/**
	 * Finish - Adds everything it needs to the queues and clears data store
	 *
	 * @return	array		Messages to display
	 */
	public function finish()
	{
		/* Content Rebuilds */
		\IPS\Task::queue( 'core', 'RebuildContainerCounts', array( 'class' => 'IPS\forums\Forum', 'count' => 0 ), 5, array( 'class' ) );
		\IPS\Task::queue( 'convert', 'RebuildContent', array( 'app' => $this->app->app_id, 'link' => 'forums_posts', 'class' => 'IPS\forums\Topic\Post' ), 2, array( 'app', 'link', 'class' ) );
		\IPS\Task::queue( 'core', 'RebuildItemCounts', array( 'class' => 'IPS\forums\Topic' ), 3, array( 'class' ) );
		\IPS\Task::queue( 'convert', 'RebuildFirstPostIds', array( 'app' => $this->app->app_id ), 2, array( 'app' ) );
		\IPS\Task::queue( 'convert', 'DeleteEmptyTopics', array( 'app' => $this->app->app_id ), 4, array( 'app' ) );

		/* Caches */
		\IPS\Task::queue( 'convert', 'RebuildTagCache', array( 'app' => $this->app->app_id, 'link' => 'forums_topics', 'class' => 'IPS\forums\Topic' ), 3, array( 'app', 'link', 'class' ) );
		
		return array( "f_forum_last_post_data", "f_rebuild_posts", "f_recounting_forums", "f_recounting_topics", "f_topic_tags_recount" );
	}
	
	/**
	 * Fix post data
	 *
	 * @param 	string		raw post data
	 * @return 	string		parsed post data
	 */
	public static function fixPostData( $post )
	{
		return \IPS\convert\Software\Core\Xenforo::fixPostData( $post );
	}
	
	/**
	 * Helper to fetch a xenforo phrase
	 *
	 * @param	string	$title	Phrase
	 * @return	string|null
	 */
	protected function getPhrase( $title )
	{
		try
		{
			return $this->db->select( 'phrase_text', 'xf_phrase', array( "title=?", $title ) )->first();
		}
		catch( \UnderflowException $e )
		{
			return NULL;
		}
	}

	/**
	 * Convert forums
	 *
	 * @return	void
	 */
	public function convertForumsForums()
	{
		$libraryClass = $this->getLibrary();
		
		$libraryClass::setKey( 'node_id' );
		
		foreach( $this->fetch( 'xf_node', 'node_id', array( $this->db->in( 'node_type_id', array( 'Category', 'Forum', 'LinkForum' ) ) ) ) AS $row )
		{
			$data = array();
			try
			{
				switch( $row['node_type_id'] )
				{
					case 'Forum':
						$data = $this->db->select( '*', 'xf_forum', array( "node_id=?", $row['node_id'] ) )->first();
						break;
					
					case 'LinkForum':
						$data = $this->db->select( '*', 'xf_link_forum', array( "node_id=?", $row['node_id'] ) )->first();
						break;
				}
			}
			catch( \UnderflowException $e ) {}
			
			$info = array(
				'id'				=> $row['node_id'],
				'name'				=> $row['title'],
				'description'		=> $row['description'],
				'topics'			=> ( isset( $data['discussion_count'] ) ) ? $data['discussion_count'] : 0,
				'posts'				=> ( isset( $data['message_count'] ) ) ? $data['message_count'] : 0,
				'last_post'			=> ( isset( $data['last_post_date'] ) ) ? $data['last_post_date'] : 0,
				'last_poster_id'	=> ( isset( $data['last_post_user_id'] ) ) ? $data['last_post_user_id'] : 0,
				'last_poster_name'	=> ( isset( $data['last_poster_name'] ) ) ? $data['last_post_username'] : '',
				'parent_id'			=> ( $row['parent_node_id'] == 0 ) ? -1 : $row['parent_node_id'],
				'position'			=> $row['display_order'],
				'last_title'		=> ( isset( $data['last_thread_title'] ) ) ? $data['last_thread_title'] : '',
				'allow_poll'		=> ( isset( $data['allow_poll'] ) ) ? $data['allow_poll'] : 0,
				'inc_postcount'		=> ( isset( $data['count_messages'] ) ) ? $data['count_messages'] : 0,
				'redirect_url'		=> ( isset( $data['link_url'] ) ) ? $data['link_url'] : NULL,
				'redirect_on'		=> ( $row['node_type_id'] == 'LinkForum' ) ? 1 : 0,
				'redirect_hits'		=> ( isset( $data['redirect_count'] ) ) ? $data['redirect_count'] : 0,
				'sub_can_post'		=> ( isset( $data['allow_posting'] ) ) ? $data['allow_posting'] : 0,
			);
			
			$libraryClass->convertForumsForum( $info );
			
			/* Follows */
			foreach( $this->db->select( '*', 'xf_forum_watch', array( "node_id=? AND notify_on=?", $row['node_id'], 'thread' ) ) AS $follow )
			{
				$libraryClass->convertFollow( array(
					'follow_app'			=> 'forums',
					'follow_area'			=> 'forum',
					'follow_rel_id'			=> $row['node_id'],
					'follow_rel_id_type'	=> 'forums_forums',
					'follow_member_id'		=> $follow['user_id'],
					'follow_is_anon'		=> 0,
					'follow_added'			=> time(),
					'follow_notify_do'		=> 1,
					'follow_notify_meta'	=> '',
					'follow_notify_freq'	=> ( $follow['send_alert'] OR $follow['send_email'] ) ? 'immediate' : 'none',
					'follow_notify_sent'	=> 0,
					'follow_visible'		=> 1,
					'follow_index_id'		=> NULL
				) );
			}
			
			$libraryClass->setLastKeyValue( $row['node_id'] );
		}
	}

	/**
	 * Convert topics
	 *
	 * @return	void
	 */
	public function convertForumsTopics()
	{
		$libraryClass = $this->getLibrary();
		
		$libraryClass::setKey( 'thread_id' );
		
		foreach( $this->fetch( 'xf_thread', 'thread_id' ) AS $row )
		{
			/* Poll */
			$poll = NULL;
			if ( $row['discussion_type'] == 'poll' )
			{
				try
				{
					$poll_data = $this->db->select( '*', 'xf_poll', array( "content_type=? AND content_id=?", 'thread', $row['thread_id'] ) )->first();
					
					$choices	= array();
					$votes		= array();
					$index		= 1;
					foreach( $this->db->select( '*', 'xf_poll_response', array( "poll_id=?", $poll_data['poll_id'] ) ) AS $choice )
					{
						$choices[$index]	= $choice['response'];
						$votes[$index]		= $choice['response_vote_count'];
						$search[$index]		= $choice['poll_response_id'];
						$index++;
					}
					
					$poll['poll_data'] = array(
						'pid'				=> $poll_data['poll_id'],
						'choices'			=> array( 1 => array(
							'question'			=> $poll_data['question'],
							'multi'				=> ( $poll_data['max_votes'] > 1 ) ? 1 : 0,
							'choice'			=> $choices,
							'votes'				=> $votes
						) ),
						'poll_question'		=> $poll_data['question'],
						'start_date'		=> $row['post_date'],
						'starter_id'		=> $row['user_id'],
						'votes'				=> array_sum( $votes ),
						'poll_view_voters'	=> $poll_data['public_votes']
					);
					
					$poll['vote_data']	= array();
					$ourVotes			= array();
					foreach( $this->db->select( '*', 'xf_poll_vote', array( "poll_id=?", $poll_data['poll_id'] ) ) AS $vote )
					{
						if ( !isset( $ourVotes[$vote['user_id']] ) )
						{
							$ourVotes[$vote['user_id']] = array( 'votes' => array() );
						}
						
						$ourVotes[$vote['user_id']]['votes'][]		= array_search( $vote['poll_response_id'], $search );
						$ourVotes[$vote['user_id']]['member_id']	= $vote['user_id'];
					}
					
					foreach( $ourVotes AS $member_id => $vote )
					{
						$poll['vote_data'][$member_id] = array(
							'member_id'			=> $vote['member_id'],
							'member_choices'	=> array( 1 => $vote['votes'] ),
						);
					}
				}
				catch( \UnderflowException $e ) {}
			}
			
			/* Approval */
			switch( $row['discussion_state'] )
			{
				case 'visible':
					$approved = 1;
					break;
				
				case 'moderation':
					$approved = 0;
					break;
				
				case 'deleted':
					$approved = -1;
					break;
			}
			
			/* Moved To */
			$moved_to = NULL;
			if ( $row['discussion_type'] == 'redirect' )
			{
				try
				{
					$redirect	= $this->db->select( '*', 'xf_thread_redirect', array( "thread_id=?", $row['thread_id'] ) )->first();
					$key		= explode( '-', $redirect['redirect_key'] );
					$moved_to	= array( $key[1], $key[2] );
				}
				catch( \UnderflowException $e ) {}
			}
			
			$info = array(
				'tid'				=> $row['thread_id'],
				'title'				=> $row['title'],
				'forum_id'			=> $row['node_id'],
				'state'				=> ( $row['discussion_open'] ) ? 'open' : 'closed',
				'posts'				=> $row['reply_count'],
				'starter_id'		=> $row['user_id'],
				'start_date'		=> $row['post_date'],
				'last_poster_id'	=> $row['last_post_user_id'],
				'last_post'			=> $row['last_post_date'],
				'starter_name'		=> $row['username'],
				'last_poster_name'	=> $row['last_post_username'],
				'poll_state'		=> $poll,
				'views'				=> $row['view_count'],
				'approved'			=> $approved,
				'pinned'			=> $row['sticky'],
				'moved_to'			=> $moved_to,
			);
			
			$libraryClass->convertForumsTopic( $info );
			
			/* Follows */
			foreach( $this->db->select( '*', 'xf_thread_watch', array( "thread_id=?", $row['thread_id'] ) ) AS $follow )
			{
				$libraryClass->convertFollow( array(
					'follow_app'			=> 'forums',
					'follow_area'			=> 'topic',
					'follow_rel_id'			=> $row['thread_id'],
					'follow_rel_id_type'	=> 'forums_topics',
					'follow_member_id'		=> $follow['user_id'],
					'follow_is_anon'		=> 0,
					'follow_added'			=> time(),
					'follow_notify_do'		=> 1,
					'follow_notify_meta'	=> '',
					'follow_notify_freq'	=> ( $follow['email_subscribe'] ) ? 'immediate' : 'none',
					'follow_notify_sent'	=> 0,
					'follow_visible'		=> 1,
					'follow_index_id'		=> NULL
				) );
			}
			
			/* Tag Prefix */
			if ( $row['prefix_id'] )
			{
				$prefix = $this->getPhrase( "thread_prefix_{$row['prefix_id']}" );
				
				if ( !is_null( $prefix ) )
				{
					$libraryClass->convertTag( array(
						'tag_meta_app'			=> 'forums',
						'tag_meta_area'			=> 'forums',
						'tag_meta_parent_id'	=> $row['node_id'],
						'tag_meta_id'			=> $row['thread_id'],
						'tag_text'				=> $prefix,
						'tag_member_id'			=> $row['user_id'],
						'tag_prefix'			=> 1, # key to this whole operation right here
					) );
				}
			}
			
			/* Other tags */
			if ( $row['tags'] )
			{
				$tags = \unserialize( $row['tags'] );
				if ( count( $tags ) )
				{
					foreach( $tags AS $key => $tag )
					{
						$libraryClass->convertTag( array(
							'tag_meta_app'			=> 'forums',
							'tag_meta_area'			=> 'forums',
							'tag_meta_parent_id'	=> $row['node_id'],
							'tag_meta_id'			=> $row['thread_id'],
							'tag_text'				=> $tag['tag'],
							'tag_member_id'			=> $row['user_id'],
							'tag_prefix'			=> 0
						) );
					}
				}
			}
			
			$libraryClass->setLastKeyValue( $row['thread_id'] );
		}
	}

	/**
	 * Convert posts
	 *
	 * @return	void
	 */
	public function convertForumsPosts()
	{
		$libraryClass = $this->getLibrary();
		
		$libraryClass::setKey( 'post_id' );
		
		foreach( $this->fetch( 'xf_post', 'post_id' ) AS $row )
		{
			/* Query for the IP */
			try
			{
				$ip = $this->db->select( 'ip', 'xf_ip', array( "ip_id=?", $row['ip_id'] ) )->first();
			}
			catch( \UnderflowException $e )
			{
				$ip = '127.0.0.1';
			}
			
			/* Figure out queued stuff */
			try
			{
				$topic_state = $this->db->select( 'discussion_state', 'xf_thread', array( "thread_id=?", $row['thread_id'] ) )->first();
				
				if ( $topic_state == 'deleted' )
				{
					$row['message_state'] = 'topic_hidden';
				}
			}
			catch( \UnderflowException $e ) {}
			
			switch( $row['message_state'] )
			{
				case 'visible':
					$queued = 0;
					break;
				
				case 'moderated':
					$queued = 1;
					break;
				
				case 'deleted':
					$queued = -1;
					break;
				
				case 'topic_hidden':
					$queued = 2;
					break;
			}
			
			$info = array(
				'pid'			=> $row['post_id'],
				'topic_id'		=> $row['thread_id'],
				'post'			=> static::fixPostData( $row['message'] ),
				'edit_time'		=> $row['last_edit_date'],
				'author_id'		=> $row['user_id'],
				'author_name'	=> $row['username'],
				'ip_address'	=> $ip,
				'post_date'		=> $row['post_date'],
				'queued'		=> $queued,
			);
			
			$post_id = $libraryClass->convertForumsPost( $info );
			
			/* Reputation */
			$likes = \unserialize( $row['like_users'] );
			if ( count( $likes ) )
			{
				foreach( $likes AS $like )
				{
					$libraryClass->convertReputation( array(
						'app'				=> 'forums',
						'type'				=> 'pid',
						'type_id'			=> $row['post_id'],
						'member_id'			=> $like['user_id'],
						'member_received'	=> $row['user_id'],
						'reaction'			=> $this->app->_session['more_info']['convertForumsPosts']['rep_like'],
						'rep_date'			=> $row['post_date']
					) );
				}
			}
			
			/* Warnings */
			foreach( $this->db->select( '*', 'xf_warning', array( "content_type=? AND content_id=?", 'post', $row['post_id'] ) ) AS $warn )
			{
				$warnId = $libraryClass->convertWarnLog( array(
					'wl_id'					=> $warn['warning_id'],
					'wl_member'				=> $warn['user_id'],
					'wl_moderator'			=> $warn['warning_user_id'],
					'wl_date'				=> $warn['warning_date'],
					'wl_points'				=> $warn['points'],
					'wl_note_member'		=> $warn['title'],
					'wl_note_mods'			=> $warn['notes'],
				) );

				/* Add a member history record for this member */
				$libraryClass->convertMemberHistory( array(
						'log_id'		=> 'w' . $warn['warning_id'],
						'log_member'	=> $warn['user_id'],
						'log_by'		=> $warn['warning_user_id'],
						'log_type'		=> 'warning',
						'log_data'		=> array( 'wid' => $warnId ),
						'log_date'		=> $warn['warning_date']
					)
				);
			}
			
			/* Edit History */
			$latestedit = 0;
			$reason		= NULL;
			$name		= NULL;
			$newText	= static::fixPostData( $row['message'] );

			foreach( $this->db->select( '*', 'xf_edit_history', array( "content_type=? AND content_id=?", 'post', $row['post_id'] ) ) AS $edit )
			{
				$libraryClass->convertEditHistory( array(
					'id'			=> $edit['edit_history_id'],
					'class'			=> 'IPS\\forums\\Topic\\Post',
					'comment_id'	=> $row['post_id'],
					'member'		=> $edit['edit_user_id'],
					'time'			=> $edit['edit_date'],
					'old'			=> static::fixPostData( $edit['old_text'] ),
					'new'			=> $newText
				) );

				$newText = static::fixPostData( $edit['old_text'] );
				
				if ( $edit['edit_date'] > $latestedit )
				{
					$latestedit = $edit['edit_date'];
				}
			}
			
			/* If we have a latest edit, then update the main post - this should really be in the library, as the converters should not be altering data */
			if ( $latestedit )
			{
				\IPS\Db::i()->update( 'forums_posts', array( 'append_edit' => 1, 'edit_time' => $latestedit ), array( "pid=?", $post_id ) );
			}
			
			$libraryClass->setLastKeyValue( $row['post_id'] );
		}
	}

	/**
	 * Convert attachments
	 *
	 * @return	void
	 */
	public function convertAttachments()
	{
		$libraryClass = $this->getLibrary();
		
		$libraryClass::setKey( 'xf_attachment.attachment_id' );
		
		$it = $this->fetch( 'xf_attachment', 'xf_attachment.attachment_id', array( "content_type=?", 'post' ) )->join( 'xf_attachment_data', 'xf_attachment.data_id = xf_attachment_data.data_id' );
		
		foreach( $it AS $row )
		{
			$topic = $this->db->select( 'thread_id', 'xf_post', array( "post_id=?", $row['content_id'] ) )->first();
			
			$map = array(
				'id1'	=> $topic,
				'id2'	=> $row['content_id'],
			);
			
			$ext = explode( '.', $row['filename'] );
			$ext = array_pop( $ext );
			
			$info = array(
				'attach_id'			=> $row['attachment_id'],
				'attach_file'		=> $row['filename'],
				'attach_date'		=> $row['upload_date'],
				'attach_member_id'	=> $row['user_id'],
				'attach_hits'		=> $row['view_count'],
				'attach_ext'		=> $ext,
				'attach_filesize'	=> $row['file_size'],
			);
			
			$physical_name	= $row['data_id'] . '-' . $row['file_hash'] . '.data';
			$group			= floor( $row['data_id'] / 1000 );
			$path			= rtrim( $this->app->_session['more_info']['convertAttachments']['attach_location'], '/' ) . '/' . $group . '/' . $physical_name;
			
			$attachId = $libraryClass->convertAttachment( $info, $map, $path );

			/* Update Post if we can */
			try
			{
				if ( $attachId !== FALSE )
				{
					$pid = $this->app->getLink( $row['content_id'], 'forums_posts' );

					$post = \IPS\Db::i()->select( 'post', 'forums_posts', array( "pid=?", $pid ) )->first();

					if ( preg_match( "/\[ATTACH(.+?)?\]" . $row['attachment_id'] . "\[\/ATTACH\]/i", $post ) )
					{
						$post = preg_replace( "/\[ATTACH(.+?)?\]" . $row['attachment_id'] . "\[\/ATTACH\]/i", '[attachment=' . $attachId . ':name]', $post );
						\IPS\Db::i()->update( 'forums_posts', array( 'post' => $post ), array( "pid=?", $pid ) );
					}
				}
			}
			catch( \UnderflowException $e ) {}
			catch( \OutOfRangeException $e ) {}
			
			$libraryClass->setLastKeyValue( $row['attachment_id'] );
		}
	}

	/**
	 * Check if we can redirect the legacy URLs from this software to the new locations
	 *
	 * @return	NULL|\IPS\Http\Url
	 */
	public function checkRedirects()
	{
		$url = \IPS\Request::i()->url();

		if( preg_match( '#/(forums|threads)/(.+?)\.([0-9]+)#i', $url->data[ \IPS\Http\Url::COMPONENT_PATH ], $matches ) )
		{
			$oldId	= (int) $matches[3];

			switch( $matches[1] )
			{
				case 'forums':
					$class	= '\IPS\forums\Forum';
					$types	= array( 'forums', 'forums_forums' );
				break;

				case 'threads':
					$class	= '\IPS\forums\Topic';
					$types	= array( 'topics', 'forums_topics' );
				break;
			}
		}
		elseif( preg_match( '#/posts/([0-9]+)/#i', $url->data[ \IPS\Http\Url::COMPONENT_PATH ], $matches ) )
		{
			$class	= '\IPS\forums\Topic\Post';
			$types	= array( 'posts', 'forums_posts' );
			$oldId	= $matches[1];
		}

		if( isset( $class ) )
		{
			try
			{
				try
				{
					$data = (string) $this->app->getLink( $oldId, $types );
				}
				catch( \OutOfRangeException $e )
				{
					$data = (string) $this->app->getLink( $oldId, $types, FALSE, TRUE );
				}
				$item = $class::load( $data );

				if( $item instanceof \IPS\Content )
				{
					if( $item->canView() )
					{
						return $item->url();
					}
				}
				elseif( $item instanceof \IPS\Node\Model )
				{
					if( $item->can( 'view' ) )
					{
						return $item->url();
					}
				}
			}
			catch( \Exception $e )
			{
				return NULL;
			}
		}

		return NULL;
	}
}