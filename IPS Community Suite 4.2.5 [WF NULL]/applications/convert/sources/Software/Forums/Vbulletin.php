<?php

/**
 * @brief		Converter vBulletin 4.x Forums Class
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

class _Vbulletin extends \IPS\convert\Software
{
	/**
	 * @brief	vBulletin 4 Stores all attachments under one table - this will store the content type for the forums app.
	 */
	protected static $postContentType		= NULL;
	
	/**
	 * @brief	The schematic for vB3 and vB4 is similar enough that we can make specific concessions in a single converter for either version.
	 */
	protected static $isLegacy					= NULL;
	
	/**
	 * @brief	Flag to indicate the post data has been fixed during conversion, and we only need to use Legacy Parser
	 */
	public static $contentFixed = TRUE;
	
	/**
	 * Constructor
	 *
	 * @param	\IPS\convert\App	The application to reference for database and other information.
	 * @param	bool				Establish a DB connection
	 * @return	void
	 * @throws	\InvalidArgumentException
	 */
	public function __construct( \IPS\convert\App $app, $needDB=TRUE )
	{
		$return = parent::__construct( $app, $needDB );
		
		if ( $needDB )
		{
			try
			{
				/* Is this vB3 or vB4? */
				if ( static::$isLegacy === NULL )
				{
					$version = $this->db->select( 'value', 'setting', array( "varname=?", 'templateversion' ) )->first();
					
					if ( mb_substr( $version, 0, 1 ) == '3' )
					{
						static::$isLegacy = TRUE;
					}
					else
					{
						static::$isLegacy = FALSE;
					}
				}
				
				
				/* If this is vB4, what is the content type ID for posts? */
				if ( static::$postContentType === NULL AND ( static::$isLegacy === FALSE OR is_null( static::$isLegacy ) ) )
				{
					static::$postContentType = $this->db->select( 'contenttypeid', 'contenttype', array( "class=?", 'Post' ) )->first();
				}
			}
			catch( \Exception $e ) {}
		}
		
		return $return;
	}
	
	/**
	 * Software Name
	 *
	 * @return	string
	 */
	public static function softwareName()
	{
		/* Child classes must override this method */
		return "vBulletin Forums (3.x/4.x)";
	}
	
	/**
	 * Software Key
	 *
	 * @return	string
	 */
	public static function softwareKey()
	{
		/* Child classes must override this method */
		return "vbulletin";
	}
	
	/**
	 * Content we can convert from this software. 
	 *
	 * @return	array
	 */
	public static function canConvert()
	{
		return array(
			'convertForumsForums'	=> array(
				'table'		=> 'forum',
				'where'		=> NULL,
			),
			'convertForumsTopics'	=> array(
				'table'		=> 'thread',
				'where'		=> NULL
			),
			'convertForumsPosts'	=> array(
				'table'		=> 'post',
				'where'		=> NULL
			),
			'convertClubForums'		=> array(
				'table'		=> 'socialgroup',
				'where'		=> array( \IPS\Db::i()->bitwiseWhere( array( 'options' => static::$bitOptions['cluboptions'] ), 'enable_group_messages' ) )
			),
			'convertClubTopics'		=> array(
				'table'		=> 'discussion',
				'where'		=> NULL
			),
			'convertClubPosts'		=> array(
				'table'		=> 'groupmessage',
				'where'		=> NULL
			),
			'convertAttachments'	=> array(
				'table'		=> 'attachment',
				'where'		=> ( static::$isLegacy === FALSE OR is_null( static::$isLegacy ) ) ? array( "contenttypeid=?", static::$postContentType ) : NULL
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
		return array( 'core' => array( 'vbulletin' ) );
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
		\IPS\Task::queue( 'core', 'RebuildItemCounts', array( 'class' => 'IPS\forums\Topic' ), 2, array( 'class' ) ); // This must run before the CMS item count task runs.
		\IPS\Task::queue( 'convert', 'RebuildFirstPostIds', array( 'app' => $this->app->app_id ), 2, array( 'app' ) );
		\IPS\Task::queue( 'convert', 'DeleteEmptyTopics', array( 'app' => $this->app->app_id ), 4, array( 'app' ) );

		/* Rebuild Leaderboard */
		\IPS\Task::queue( 'core', 'RebuildReputationLeaderboard', array(), 4 );
		\IPS\Db::i()->delete('core_reputation_leaderboard_history');

		/* Caches */
		\IPS\Task::queue( 'convert', 'RebuildTagCache', array( 'app' => $this->app->app_id, 'link' => 'forums_topics', 'class' => 'IPS\forums\Topic' ), 3, array( 'app', 'link', 'class' ) );
		
		return array( "f_forum_last_post_data", "f_rebuild_posts", "f_recounting_forums", "f_recounting_topics", "f_topic_tags_recount" );
	}
	
	/**
	 * Fix Post Data
	 *
	 * @param	string	Post
	 * @return	string	Fixed Posts
	 */
	public static function fixPostData( $post )
	{
		return \IPS\convert\Software\Core\Vbulletin::fixPostData( $post );
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
					'rep_neutral'	=> array(
						'field_class'		=> 'IPS\\Helpers\\Form\\Radio',
						'field_default'		=> NULL,
						'field_required'	=> TRUE,
						'field_extra'		=> array( 'parse' => 'image', 'options' => $options, 'descriptions' => $descriptions ),
						'field_hint'		=> NULL,
						'field_validation'	=> NULL,
					),
					'rep_positive'	=> array(
						'field_class'		=> 'IPS\\Helpers\\Form\\Radio',
						'field_default'		=> NULL,
						'field_required'	=> TRUE,
						'field_extra'		=> array( 'parse' => 'image', 'options' => $options, 'descriptions' => $descriptions ),
						'field_hint'		=> NULL,
						'field_validation'	=> NULL,
					),
					'rep_negative'	=> array(
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
					'file_location' => array(
						'field_class'			=> 'IPS\\Helpers\\Form\\Radio',
						'field_default'			=> 'database',
						'field_required'		=> TRUE,
						'field_extra'			=> array(
							'options'				=> array(
								'database'				=> \IPS\Member::loggedIn()->language()->addToStack( 'database' ),
								'file_system'			=> \IPS\Member::loggedIn()->language()->addToStack( 'file_system' ),
							),
							'userSuppliedInput'	=> 'file_system',
						),
						'field_hint'			=> NULL,
						'field_validation'		=> function( $value ) { if ( !@is_dir( $value ) ) { throw new \DomainException( 'path_invalid' ); } },
					)
				);
				break;
		}
		
		return ( isset( $return[ $method ] ) ) ? $return[ $method ] : array();
	}

	/**
	 * Convert forums
	 *
	 * @return	void
	 */
	public function convertForumsForums()
	{
		$libraryClass = $this->getLibrary();
		
		$libraryClass::setKey( 'forumid' );
		
		foreach( $this->fetch( 'forum', 'forumid' ) AS $forum )
		{
			$self = $this;
			$checkpermission = function( $name, $perm ) use ( $forum, $self )
			{
				$key = $name;
				if ( $name == 'forumoptions' )
				{
					$key = 'options';
				}
				
				if ( $forum[$key] & $self::$bitOptions[$name][$perm] )
				{
					return TRUE;
				}
				
				return FALSE;
			};
			
			$info = array(
				'id'					=> $forum['forumid'],
				'name'					=> $forum['title'],
				'description'			=> $forum['description'],
				'topics'				=> $forum['threadcount'],
				'posts'					=> $forum['replycount'],
				'last_post'				=> $forum['lastpost'],
				'last_poster_id'		=> ( static::$isLegacy === FALSE or is_null( static::$isLegacy ) ) ? $forum['lastposterid'] : 0,
				'last_poster_name'		=> $forum['lastposter'],
				'parent_id'				=> $forum['parentid'],
				'position'				=> $forum['displayorder'],
				'password'				=> $forum['password'] ?: NULL,
				'last_title'			=> $forum['lastthread'],
				'preview_posts'			=> $checkpermission( 'forumoptions', 'moderatenewpost' ),
				'inc_postcount'			=> $checkpermission( 'forumoptions', 'countposts' ),
				'redirect_url'			=> $forum['link'],
				'sub_can_post'			=> $checkpermission( 'forumoptions', 'cancontainthreads' ),
				'forum_allow_rating'	=> $checkpermission( 'forumoptions', 'allowratings' ),
			);
			
			$libraryClass->convertForumsForum( $info );
			
			/* Follows for this forum */
			foreach( $this->db->select( '*', 'subscribeforum', array( "forumid=?", $forum['forumid'] ) ) AS $follow )
			{
				$frequency = 'none';
				
				switch( $follow['emailupdate'] )
				{
					case 1:
						$frequency = 'immediate';
						break;
					
					case 2:
						$frequency = 'daily';
						break;
					
					case 3:
						$frequency = 'weekly';
						break;
				}
				
				$libraryClass->convertFollow( array(
					'follow_app'			=> 'forums',
					'follow_area'			=> 'forum',
					'follow_rel_id'			=> $forum['forumid'],
					'follow_rel_id_type'	=> 'forums_forums',
					'follow_member_id'		=> $follow['userid'],
					'follow_is_anon'		=> 0,
					'follow_added'			=> time(),
					'follow_notify_do'		=> 1,
					'follow_notify_meta'	=> '',
					'follow_notify_freq'	=> $frequency,
					'follow_notify_sent'	=> 0,
					'follow_visible'		=> 1,
					'follow_index_id'		=> NULL
				) );
			}
			
			$libraryClass->setLastKeyValue( $forum['forumid'] );
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
		
		$libraryClass::setKey( 'threadid' );
		
		foreach( $this->fetch( 'thread', 'threadid' ) AS $topic )
		{
			/* Pesky Polls */
			$poll		= NULL;
			$lastVote	= 0;
			if ( $topic['pollid'] > 0 )
			{
				try
				{
					$pollData = $this->db->select( '*', 'poll', array( "pollid=?", $topic['pollid'] ) )->first();
					
					$lastVote = $pollData['lastvote'];
					
					$choices	= array();
					$index		= 1;
					foreach( explode( '|||', $pollData['options'] ) AS $choice )
					{
						$choices[$index] = trim( $choice );
						$index++;
					}
					
					/* Reset Index */
					$index		= 1;
					$votes		= array();
					$numvotes	= 0;
					foreach( explode( '|||', $pollData['votes'] ) AS $vote )
					{
						$votes[$index] = $vote;
						$index++;
						
						$numvotes += $vote;
					}
					
					$poll = array();
					$poll['poll_data'] = array(
						'pid'				=> $pollData['pollid'],
						'choices'			=> array( 1 => array(
							'question'			=> $pollData['question'],
							'multi'				=> $pollData['multiple'],
							'choice'			=> $choices,
							'votes'				=> $votes
						) ),
						'poll_question'		=> $pollData['question'],
						'start_date'		=> $pollData['dateline'],
						'starter_id'		=> $topic['postuserid'],
						'votes'				=> $numvotes,
						'poll_view_voters'	=> $pollData['public']
					);
					
					$poll['vote_data'] = array();
					$ourVotes = array();
					foreach( $this->db->select( '*', 'pollvote', array( "pollid=?", $pollData['pollid'] ) ) AS $vote )
					{
						if ( !isset( $ourVotes[$vote['userid']] ) )
						{
							/* Create our structure - vB stores each individual vote as a different row whereas we combine them per user */
							$ourVotes[$vote['userid']] = array( 'votes' => array() );
						}
						
						$ourVotes[$vote['userid']]['votes'][]		= $vote['voteoption'];
						
						/* These don't matter - just use the latest one */
						$ourVotes[$vote['userid']]['vid']			= $vote['pollvoteid'];
						$ourVotes[$vote['userid']]['vote_date'] 	= $vote['votedate'];
						$ourVotes[$vote['userid']]['member_id']		= $vote['userid'];
					}
					
					/* Now we need to re-wrap it all for storage */
					foreach( $ourVotes AS $member_id => $vote )
					{
						$poll['vote_data'][$member_id] = array(
							'vid'				=> $vote['vid'],
							'vote_date'			=> $vote['vote_date'],
							'member_id'			=> $vote['member_id'],
							'member_choices'	=> array( 1 => $vote['votes'] ),
						);
					}
				}
				catch( \UnderflowException $e ) {} # if the poll is missing, don't bother
			}
			
			$info = array(
				'tid'				=> $topic['threadid'],
				'title'				=> $topic['title'],
				'forum_id'			=> $topic['forumid'],
				'state'				=> $topic['open'] ? 'open' : 'closed',
				'posts'				=> $topic['replycount'],
				'starter_id'		=> $topic['postuserid'],
				'start_date'		=> $topic['dateline'],
				'last_poster_id'	=> ( static::$isLegacy === FALSE OR is_null( static::$isLegacy ) ) ? $topic['lastposterid'] : NULL,
				'last_post'			=> $topic['lastpost'],
				'starter_name'		=> $topic['postusername'],
				'last_poster_name'	=> $topic['lastposter'],
				'poll_state'		=> $poll,
				'last_vote'			=> $lastVote,
				'views'				=> $topic['views'],
				'approved'			=> $topic['visible'],
				'pinned'			=> $topic['sticky'],
				'topic_hiddenposts'	=> $topic['hiddencount'] + $topic['deletedcount']
			);
			
			unset( $poll );
			
			$topic_id = $libraryClass->convertForumsTopic( $info );
			
			/* Follows */
			foreach( $this->db->select( '*', 'subscribethread', array( "threadid=?", $topic['threadid'] ) ) AS $follow )
			{
				$frequency = 'none';
				switch( $follow['emailupdate'] )
				{
					case 1:
						$frequency = 'immediate';
						break;
					
					case 2:
						$frequency = 'daily';
						break;
					
					case 3:
						$frequency = 'weekly';
						break;
				}
				
				$libraryClass->convertFollow( array(
					'follow_app'			=> 'forums',
					'follow_area'			=> 'topic',
					'follow_rel_id'			=> $topic['threadid'],
					'follow_rel_id_type'	=> 'forums_topics',
					'follow_member_id'		=> $follow['userid'],
					'follow_is_anon'		=> 0,
					'follow_added'			=> time(),
					'follow_notify_do'		=> 1,
					'follow_notify_meta'	=> '',
					'follow_notify_freq'	=> $frequency,
					'follow_notify_sent'	=> 0,
					'follow_visible'		=> 1,
					'follow_index_id'		=> NULL
				) );
			}
			
			/* Ratings */
			foreach( $this->db->select( '*', 'threadrate', array( "threadid=?", $topic['threadid'] ) ) AS $rating )
			{
				$libraryClass->convertRating( array(
					'id'		=> $rating['threadrateid'],
					'class'		=> 'IPS\\forums\\Topic',
					'item_link'	=> 'forums_topics',
					'item_id'	=> $rating['threadid'],
					'ip'		=> $rating['ipaddress'],
					'rating'	=> $rating['vote'],
					'member'	=> $rating['userid']
				) );
			}
			
			/* Tag Prefix */
			try
			{
				$prefix	= $this->db->select( '*', 'prefix', array( "prefixid=?", $topic['prefixid'] ) )->first();
				$lang	= $this->db->select( '*', 'phrase', array( "varname=?", "prefix_{$prefix['prefixid']}_title_plain" ) )->first();
				
				$libraryClass->convertTag( array(
					'tag_meta_app'			=> 'forums',
					'tag_meta_area'			=> 'forums',
					'tag_meta_parent_id'	=> $topic['forumid'],
					'tag_meta_id'			=> $topic['threadid'],
					'tag_text'				=> $lang['text'],
					'tag_member_id'			=> $topic['postuserid'],
					'tag_prefix'			=> 1, # key to this whole operation right here
				) );
			}
			catch( \UnderflowException $e ) {}
			
			/* Tags */
			if( $topic['taglist'] !== NULL AND !empty( $topic['taglist'] ) )
			{
				$tags = explode( ',', $topic['taglist'] );
				if ( count( $tags ) )
				{
					foreach( $tags AS $tag )
					{
						$libraryClass->convertTag( array(
							'tag_meta_app'			=> 'forums',
							'tag_meta_area'			=> 'forums',
							'tag_meta_parent_id'	=> $topic['forumid'],
							'tag_meta_id'			=> $topic['threadid'],
							'tag_text'				=> $tag,
							'tag_member_id'			=> $topic['postuserid'],
							'tag_prefix'			=> 0,
						) );
					}
				}
			}
			
			$libraryClass->setLastKeyValue( $topic['threadid'] );
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
		
		$libraryClass::setKey( 'postid' );
		
		foreach( $this->fetch( 'post', 'postid' ) AS $post )
		{
			$info = array(
				'pid'				=> $post['postid'],
				'topic_id'			=> $post['threadid'],
				'post'				=> static::fixPostData( $post['pagetext'] ),
				'author_id'			=> $post['userid'],
				'author_name'		=> $post['username'],
				'ip_address'		=> $post['ipaddress'],
				'post_date'			=> $post['dateline'],
				'queued'			=> ( $post['visible'] >= 2 ) ? -1 : ( ( $post['visible'] == 0 ) ? 1 : 0 ),
				'post_htmlstate'	=> ( static::$isLegacy === FALSE AND in_array( $post['htmlstate'], array( 'on', 'on_nl2br' ) ) ) ? 1 : 0,
			);
			
			$post_id = $libraryClass->convertForumsPost( $info );
			
			/* Reputation */
			foreach( $this->db->select( '*', 'reputation', array( "postid=?", $post['postid'] ) ) AS $rep )
			{
				$reaction = ( $rep['reputation'] > 0 ) ? 
					$this->app->_session['more_info']['convertForumsPosts']['rep_positive'] : 
					( ( $rep['reputation'] == 0 ) ? $this->app->_session['more_info']['convertForumsPosts']['rep_neutral'] : 
						$this->app->_session['more_info']['convertForumsPosts']['rep_negative'] );

				$libraryClass->convertReputation( array(
					'id'				=> $rep['reputationid'],
					'app'				=> 'forums',
					'type'				=> 'pid',
					'type_id'			=> $post['postid'],
					'member_id'			=> $rep['whoadded'],
					'member_received'	=> $rep['userid'],
					'rep_date'			=> $rep['dateline'],
					'reaction'			=> $reaction
				) );
			}
			
			/* Edit History */
			$latestedit = 0;
			$reason		= NULL;
			$name		= NULL;
			$newText	= static::fixPostData( $post['pagetext'] );

			foreach( $this->db->select( '*', 'postedithistory', array( "postid=?", $post['postid'] ) ) AS $edit )
			{
				$libraryClass->convertEditHistory( array(
					'id'			=> $edit['postedithistoryid'],
					'class'			=> 'IPS\\forums\\Topic\\Post',
					'comment_id'	=> $post['postid'],
					'member'		=> $edit['userid'],
					'time'			=> $edit['dateline'],
					'old'			=> static::fixPostData( $edit['pagetext'] ),
					'new'			=> $newText
				) );
				
				$newText = static::fixPostData( $edit['pagetext'] );
				
				if ( $edit['dateline'] > $latestedit )
				{
					$latestedit = $edit['dateline'];
					$reason		= $edit['reason'];
					$name		= $edit['username'];
				}
			}
			
			/* Warnings */
			foreach( $this->db->select( '*', 'infraction', array( "postid=?", $post['postid'] ) ) AS $warn )
			{
				$warnId = $libraryClass->convertWarnLog( array(
					'wl_id'					=> $warn['infractionid'],
					'wl_member'				=> $warn['userid'],
					'wl_moderator'			=> $warn['whoadded'],
					'wl_date'				=> $warn['dateline'],
					'wl_points'				=> $warn['points'],
					'wl_note_member'		=> $warn['note'],
					'wl_note_mods'			=> $warn['customreason'],
				) );

				/* Add a member history record for this member */
				$libraryClass->convertMemberHistory( array(
						'log_id'		=> 'w' . $warn['infractionid'],
						'log_member'	=> $warn['userid'],
						'log_by'		=> $warn['whoadded'],
						'log_type'		=> 'warning',
						'log_data'		=> array( 'wid' => $warnId ),
						'log_date'		=> $warn['dateline']
					)
				);
			}
			
			/* If we have a latest edit, then update the main post - this should really be in the library, as the converters should not be altering data */
			if ( $latestedit )
			{
				\IPS\Db::i()->update( 'forums_posts', array( 'append_edit' => 1, 'edit_time' => $latestedit, 'edit_name' => $name, 'post_edit_reason' => $reason ), array( "pid=?", $post_id ) );
			}
			
			$libraryClass->setLastKeyValue( $post['postid'] );
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
		
		$libraryClass::setKey( 'attachmentid' );
		
		$where			= NULL;
		$column			= NULL;
		
		if ( static::$isLegacy === FALSE OR is_null( static::$isLegacy ) )
		{
			$where			= array( "contenttypeid=?", static::$postContentType );
			$column			= 'contentid';
		}
		else
		{
			$column			= 'postid';
		}
		
		foreach( $this->fetch( 'attachment', 'attachmentid', $where ) AS $attachment )
		{
			try
			{
				$topic_id = $this->db->select( 'threadid', 'post', array( "postid=?", $attachment[$column] ) )->first();
			}
			catch( \UnderflowException $e )
			{
				/* If the topic is missing, there isn't much we can do. */
				$libraryClass->setLastKeyValue( $attachment['attachmentid'] );
				continue;
			}
			
			if ( static::$isLegacy === FALSE OR is_null( static::$isLegacy ) )
			{
				try
				{
					$filedata = $this->db->select( '*', 'filedata', array( "filedataid=?", $attachment['filedataid'] ) )->first();
				}
				catch( \UnderflowException $e )
				{
					/* If the filedata row is missing, there isn't much we can do. */
					$libraryClass->setLastKeyValue( $attachment['attachmentid'] );
					continue;
				}
			}
			else
			{
				$filedata				= $attachment;
				$filedata['filedataid']	= $attachment['attachmentid'];
			}
			
			$map = array(
				'id1'		=> $topic_id,
				'id2'		=> $attachment[$column]
			);
			
			$info = array(
				'attach_id'			=> $attachment['attachmentid'],
				'attach_file'		=> $attachment['filename'],
				'attach_date'		=> $attachment['dateline'],
				'attach_member_id'	=> $attachment['userid'],
				'attach_hits'		=> $attachment['counter'],
				'attach_ext'		=> $filedata['extension'],
				'attach_filesize'	=> $filedata['filesize'],
			);
			
			if ( $this->app->_session['more_info']['convertAttachments']['file_location'] == 'database' )
			{
				/* Simples! */
				$data = $filedata['filedata'];
				$path = NULL;
			}
			else
			{
				$data = NULL;
				$path = implode( '/', preg_split( '//', $filedata['userid'], -1, PREG_SPLIT_NO_EMPTY ) );
				$path = rtrim( $this->app->_session['more_info']['convertAttachments']['file_location'], '/' ) . '/' . $path . '/' . $filedata['filedataid'] . '.attach';
			}
			
			$attach_id = $libraryClass->convertAttachment( $info, $map, $path, $data );
			
			/* Do some re-jiggery on the post itself to make sure attachment displays */
			if ( $attach_id !== FALSE )
			{
				try
				{
					$pid = $this->app->getLink( $attachment[$column], 'forums_posts' );
					
					$post = \IPS\Db::i()->select( 'post', 'forums_posts', array( "pid=?", $pid ) )->first();
					
					if ( preg_match( "/\[ATTACH(.+?)?\]".$attachment['attachmentid']."\[\/ATTACH\]/i", $post ) )
					{
						$post = preg_replace( "/\[ATTACH(.+?)?\]" . $attachment['attachmentid'] . "\[\/ATTACH\]/i", '[attachment=' . $attach_id . ':name]', $post );

						\IPS\Db::i()->update( 'forums_posts', array( 'post' => $post ), array( "pid=?", $pid ) );
					}
				}
				catch( \UnderflowException $e ) {}
				catch( \OutOfRangeException $e ) {}
			}
			
			$libraryClass->setLastKeyValue( $attachment['attachmentid'] );
		}
	}

	/**
	 * Convert Club Forums
	 *
	 * @return	void
	 */
	public function convertClubForums()
	{
		$libraryClass = $this->getLibrary();
		$libraryClass::setKey( 'groupid' );
		foreach( $this->fetch( 'socialgroup', 'groupid', array( \IPS\Db::i()->bitwiseWhere( array( 'options' => static::$bitOptions['cluboptions'] ), 'enable_group_messages' ) ) ) AS $row )
		{
			$libraryClass->convertClubForum( array(
				'id'			=> "clubforum{$row['groupid']}",
				'name'			=> "{$row['name']} Topics",
				'description'	=> "<p>{$row['description']}</p>",
				'topics'		=> $row['discussions'],
				'club_id'		=> $row['groupid']
			) );

			$libraryClass->setLastKeyValue( $row['groupid'] );
		}
	}

	/**
	 * Convert Club Topics
	 *
	 * @return	void
	 */
	public function convertClubTopics()
	{
		$libraryClass = $this->getLibrary();
		$libraryClass::setKey( 'discussionid' );
		foreach( $this->fetch( 'discussion', 'discussionid' ) AS $row )
		{
			try
			{
				$firstpost = $this->db->select( '*', 'groupmessage', array( "gmid=?", $row['firstpostid'] ) )->first();
			}
			catch( \UnderflowException $e )
			{
				$libraryClass->setLastKeyValue( $row['discussionid'] );
				continue;
			}

			switch( $firstpost['state'] )
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

			$libraryClass->convertClubTopic( array(
				'tid'				=> "clubtopic{$row['discussionid']}",
				'title'				=> $firstpost['title'],
				'forum_id'			=> "clubforum{$row['groupid']}",
				'state'				=> 'open',
				'starter_id'		=> $row['lastposterid'],
				'start_date'		=> $firstpost['dateline'],
				'last_poster_id'	=> $row['lastposterid'],
				'last_post'			=> $row['lastpost'],
				'starter_name'		=> $firstpost['postusername'],
				'last_poster_name'	=> $row['lastposter'],
				'approved'			=> $approved,
			) );

			$libraryClass->setLastKeyValue( $row['discussionid'] );
		}
	}

	/**
	 * Convert Club Posts
	 *
	 * @return	void
	 */
	public function convertClubPosts()
	{
		$libraryClass = $this->getLibrary();
		$libraryClass::setKey( 'gmid' );
		foreach( $this->fetch( 'groupmessage', 'gmid' ) AS $row )
		{
			switch( $row['state'] )
			{
				case 'visible':
					$queued = 0;
					break;

				case 'moderation':
					$queued = 1;
					break;

				case 'deleted':
					$queued = -1;
					break;
			}

			$libraryClass->convertClubPost( array(
				'pid'			=> "clubpost{$row['gmid']}",
				'topic_id'		=> "clubtopic{$row['discussionid']}",
				'post'			=> static::fixPostData( $row['pagetext'] ),
				'author_id'		=> $row['postuserid'],
				'author_name'	=> $row['postusername'],
				'post_date'		=> $row['dateline'],
				'queued'		=> $queued,
			) );
			$libraryClass->setLastKeyValue( $row['gmid'] );
		}
	}
	
	/* !vBulletin Stuff */
	
	/**
	 * @brief	Silly Bitwise
	 */
	public static $bitOptions = array (
		'forumoptions' => array(
			'active' => 1,
			'allowposting' => 2,
			'cancontainthreads' => 4,
			'moderatenewpost' => 8,
			'moderatenewthread' => 16,
			'moderateattach' => 32,
			'allowbbcode' => 64,
			'allowimages' => 128,
			'allowhtml' => 256,
			'allowsmilies' => 512,
			'allowicons' => 1024,
			'allowratings' => 2048,
			'countposts' => 4096,
			'canhavepassword' => 8192,
			'indexposts' => 16384,
			'styleoverride' => 32768,
			'showonforumjump' => 65536,
			'prefixrequired' => 131072,
			'allowvideos' => 262144,
			'bypassdp' => 524288,
			'displaywrt' => 1048576,
			'canreputation' => 2097152,
		),
		'cluboptions'				=> array(
			'owner_mod_queue'			=> 1,
			'join_to_view'				=> 2,
			'enable_group_messages'		=> 4,
			'enable_group_albums'		=> 8,
			'only_owner_discussions'	=> 16
		)
	);

	/**
	 * Check if we can redirect the legacy URLs from this software to the new locations
	 *
	 * @return	NULL|\IPS\Http\Url
	 */
	public function checkRedirects()
	{
		$url = \IPS\Request::i()->url();

		/* If it looks like a VBSEO URL, rewrite it */
		if( mb_strpos( $url->data[ \IPS\Http\Url::COMPONENT_PATH ], '.html' ) !== FALSE )
		{
			/* Paginated topics */
			if( preg_match( "/\/(\d+)\-.+?\-(\d+)\.html/", $url->data[ \IPS\Http\Url::COMPONENT_PATH ], $matches ) )
			{
				$url = $url->setPath( preg_replace( "/\/(\d+)\-.+?\-(\d+)\.html/", "/showthread.php", $url->data[ \IPS\Http\Url::COMPONENT_PATH ] ) )->setQueryString( array( 't' => $matches[1], 'page' => $matches[2] ) );
				\IPS\Request::i()->t	= $matches[1];
				\IPS\Request::i()->page	= $matches[2];
			}
			elseif( preg_match( "/\/(\d+)\-.+?\.html/", $url->data[ \IPS\Http\Url::COMPONENT_PATH ], $matches ) )
			{
				$url = $url->setPath( preg_replace( "/\/(\d+)\-.+?\.html/", "/showthread.php", $url->data[ \IPS\Http\Url::COMPONENT_PATH ] ) )->setQueryString( 't', $matches[1] );
				\IPS\Request::i()->t	= $matches[1];
			}
		}

		/* Forum URLs are the same across VB 3.8 and VB 4 */
		if( mb_strpos( $url->data[ \IPS\Http\Url::COMPONENT_PATH ], 'forumdisplay.php' ) !== FALSE )
		{
			/* Forum URLs can be in one of 3 formats really...
				* /forumdisplay.php/1-name
				* /forums/1-name
				* /forumdisplay.php?f=1
			*/
			$path = $url->data[ \IPS\Http\Url::COMPONENT_PATH ];
			if( mb_strpos( $path, 'forumdisplay.php' ) !== FALSE )
			{
				if( isset( \IPS\Request::i()->f ) )
				{
					$oldId	= \IPS\Request::i()->f;
				}
				else
				{
					$queryStringPieces	= explode( '-', mb_substr( $path, mb_strpos( $path, 'forumdisplay.php/' ) + mb_strlen( 'forumdisplay.php/' ) ) );
					$oldId				= $queryStringPieces[0];
				}
			}

			if( isset( $oldId ) )
			{
				try
				{
					$data = (string) $this->app->getLink( $oldId, array( 'forums', 'forums_forums' ) );
					$item = \IPS\forums\Forum::load( $data );

					if( $item->can( 'view' ) )
					{
						return $item->url();
					}
				}
				catch( \Exception $e )
				{
					return NULL;
				}
			}
		}
		elseif( preg_match( '#/forums/([0-9]+)#i', $url->data[ \IPS\Http\Url::COMPONENT_PATH ], $matches ) )
		{
			try
			{
				$data = (string) $this->app->getLink( (int) $matches[1], array( 'forums', 'forums_forums' ) );
				$item = \IPS\forums\Forum::load( $data );

				if( $item->can( 'view' ) )
				{
					return $item->url();
				}
			}
			catch( \Exception $e )
			{
				return NULL;
			}
		}
		/* And then post URLs, simple */
		elseif( 
			( mb_strpos( $url->data[ \IPS\Http\Url::COMPONENT_PATH ], 'showthread.php' ) !== FALSE OR
			  mb_strpos( $url->data[ \IPS\Http\Url::COMPONENT_PATH ], 'showpost.php' ) !== FALSE OR 
			  preg_match( '#/threads/([0-9]+)#i', $url->data[ \IPS\Http\Url::COMPONENT_PATH ] )
			)
			AND isset( \IPS\Request::i()->p )
		)
		{
			try
			{
				try
				{
					$data = (string) $this->app->getLink( (int) \IPS\Request::i()->p, array( 'posts', 'forums_posts' ) );
				}
				catch( \OutOfRangeException $e )
				{
					/* Try the main table */
					$data = (string) $this->app->getLink( (int) \IPS\Request::i()->p, array( 'posts', 'forums_posts' ), FALSE, TRUE );
				}
				$item = \IPS\forums\Topic\Post::load( $data );

				if( $item->canView() )
				{
					return $item->url();
				}
			}
			catch( \Exception $e )
			{
				return NULL;
			}
		}
		/* And then topic URLs, same idea */
		elseif( mb_strpos( $url->data[ \IPS\Http\Url::COMPONENT_PATH ], 'showthread.php' ) !== FALSE )
		{
			/* Topic URLs can be in one of 3 formats really...
				* /showthread.php/1-name
				* /threads/1-name
				* /showthread.php?t=1
			*/
			$path = $url->data[ \IPS\Http\Url::COMPONENT_PATH ];
			if( mb_strpos( $path, 'showthread.php' ) !== FALSE )
			{
				if( isset( \IPS\Request::i()->t ) )
				{
					$oldId	= \IPS\Request::i()->t;
				}
				else
				{
					$queryStringPieces	= explode( '-', mb_substr( $path, mb_strpos( $path, 'showthread.php/' ) + mb_strlen( 'showthread.php/' ) ) );
					$oldId				= $queryStringPieces[0];
				}
			}
			elseif( preg_match( '#/forums/([0-9]+)#i', $url->data[ \IPS\Http\Url::COMPONENT_PATH ], $matches ) )
			{
				$oldId	= (int) $matches[1];
			}

			if( isset( $oldId ) )
			{
				try
				{
					try
					{
						$data = (string) $this->app->getLink( $oldId, array( 'topics', 'forums_topics' ) );
					}
					catch( \OutOfRangeException $e )
					{
						/* Try the main table */
						$data = (string) $this->app->getLink( $oldId, array( 'topics', 'forums_topics' ), FALSE, TRUE );
					}
					$item = \IPS\forums\Topic::load( $data );

					if( $item->canView() )
					{
						return $item->url();
					}
				}
				catch( \Exception $e )
				{
					return NULL;
				}
			}
		}
		elseif( preg_match( '#/threads/([0-9]+)#i', $url->data[ \IPS\Http\Url::COMPONENT_PATH ], $matches ) )
		{
			try
			{
				try
				{
					$data = (string) $this->app->getLink( (int) $matches[1], array( 'topics', 'forums_topics' ) );
				}
				catch( \OutOfRangeException $e )
				{
					/* Try the main table */
					$data = (string) $this->app->getLink( (int) $matches[1], array( 'topics', 'forums_topics' ), FALSE, TRUE );
				}
				$item = \IPS\forums\Topic::load( $data );

				if( $item->canView() )
				{
					return $item->url();
				}
			}
			catch( \Exception $e )
			{
				return NULL;
			}
		}
		/* And finally, archives */
		elseif( mb_strpos( $url->data[ \IPS\Http\Url::COMPONENT_PATH ], 'archive/index.php' ) !== FALSE )
		{
			$request		= str_replace( '/archive/index.php/', '', $_SERVER['REQUEST_URI'] );
			$parameters		= explode( '-', $request );
			$parameters[1]	= str_replace( '.html', '', $parameters[1] );

			try
			{
				switch( $parameters[0] )
				{
					case 't':
						try
						{
							$data = $this->getLink( (string) $parameters[1], array( 'topics', 'forums_topics' ) );
						}
						catch( \OutOfRangeException $e )
						{
							$data = $this->getLink( (string) $parameters[1], array( 'topics', 'forums_topics', FALSE, TRUE ) );
						}
						$item = \IPS\forums\Topic::load( $data );

						if( $item->canView() )
						{
							return $item->url();
						}
					break;

					case 'f':
						$data = $this->app->getLink( (string) $parameters[1], array( 'forums', 'forums_forums' ) );
						$item = \IPS\forums\Forum::load( $data );

						if( $item->can( 'view' ) )
						{
							return $item->url();
						}
					break;
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