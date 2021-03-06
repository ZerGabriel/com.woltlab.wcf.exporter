<?php
namespace wcf\system\exporter;
use wbb\data\board\Board;
use wcf\data\like\Like;
use wcf\data\user\group\UserGroup;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;
use wcf\util\StringUtil;
use wcf\util\UserUtil;

/**
 * Exporter for IP.Board 4.x
 * 
 * @author	Marcel Werk
 * @copyright	2001-2015 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework
 */
class IPB4xExporter extends AbstractExporter {
	/**
	 * language statement
	 * @var \wcf\system\database\statement\PreparedStatement
	 */
	private $languageStatement = null;
	
	/**
	 * ipb default language
	 * @var integer
	 */
	private $defaultLanguageID = null;
	
	/**
	 * board cache
	 * @var	array
	 */
	protected $boardCache = array();
	
	/**
	 * @see	\wcf\system\exporter\AbstractExporter::$methods
	 */
	protected $methods = array(
		'com.woltlab.wcf.user' => 'Users',
		'com.woltlab.wcf.user.group' => 'UserGroups',
		'com.woltlab.wcf.user.follower' => 'Followers',
		'com.woltlab.wcf.user.comment' => 'StatusUpdates',
		'com.woltlab.wcf.user.comment.response' => 'StatusReplies',
		'com.woltlab.wcf.user.avatar' => 'UserAvatars',
		'com.woltlab.wcf.user.option' => 'UserOptions',
		'com.woltlab.wcf.conversation' => 'Conversations',
		'com.woltlab.wcf.conversation.message' => 'ConversationMessages',
		'com.woltlab.wcf.conversation.user' => 'ConversationUsers',
		'com.woltlab.wcf.conversation.attachment' => 'ConversationAttachments',
		'com.woltlab.wbb.board' => 'Boards',
		'com.woltlab.wbb.thread' => 'Threads',
		'com.woltlab.wbb.post' => 'Posts',
		'com.woltlab.wbb.attachment' => 'PostAttachments',
		'com.woltlab.wbb.watchedThread' => 'WatchedThreads',
		'com.woltlab.wbb.poll' => 'Polls',
		'com.woltlab.wbb.poll.option.vote' => 'PollOptionVotes',
		'com.woltlab.wbb.like' => 'Likes'
	);
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getSupportedData()
	 */
	public function getSupportedData() {
		return array(
			'com.woltlab.wcf.user' => array(
				'com.woltlab.wcf.user.group',
				'com.woltlab.wcf.user.avatar',
				'com.woltlab.wcf.user.option',
				'com.woltlab.wcf.user.comment',
				'com.woltlab.wcf.user.follower'
			),
			'com.woltlab.wbb.board' => array(
				'com.woltlab.wbb.attachment',
				'com.woltlab.wbb.poll',
				'com.woltlab.wbb.watchedThread',
				'com.woltlab.wbb.like'
			),
			'com.woltlab.wcf.conversation' => array(
				'com.woltlab.wcf.conversation.attachment'
			)
		);
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateDatabaseAccess()
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		$sql = "SELECT COUNT(*) FROM ".$this->databasePrefix."core_admin_permission_rows";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateFileAccess()
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData) || in_array('com.woltlab.wbb.attachment', $this->selectedData) || in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData)) {
			if (empty($this->fileSystemPath) || !@file_exists($this->fileSystemPath . 'conf_global.php')) return false;
		}
		
		return true;
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getQueue()
	 */
	public function getQueue() {
		$queue = array();
		
		// user
		if (in_array('com.woltlab.wcf.user', $this->selectedData)) {
			if (in_array('com.woltlab.wcf.user.group', $this->selectedData)) {
				$queue[] = 'com.woltlab.wcf.user.group';
			}
			if (in_array('com.woltlab.wcf.user.option', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.option';
			$queue[] = 'com.woltlab.wcf.user'; 
			if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.avatar';
				
			if (in_array('com.woltlab.wcf.user.comment', $this->selectedData)) {
				$queue[] = 'com.woltlab.wcf.user.comment';
				$queue[] = 'com.woltlab.wcf.user.comment.response';
			}
			
			if (in_array('com.woltlab.wcf.user.follower', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.follower';
			
			// conversation
			if (in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
				$queue[] = 'com.woltlab.wcf.conversation';
				$queue[] = 'com.woltlab.wcf.conversation.message';
				$queue[] = 'com.woltlab.wcf.conversation.user';
				
				if (in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.attachment';
			}
		}
		
		// board
		if (in_array('com.woltlab.wbb.board', $this->selectedData)) {
			$queue[] = 'com.woltlab.wbb.board';
			$queue[] = 'com.woltlab.wbb.thread';
			$queue[] = 'com.woltlab.wbb.post';
			
			if (in_array('com.woltlab.wbb.attachment', $this->selectedData)) $queue[] = 'com.woltlab.wbb.attachment';
			if (in_array('com.woltlab.wbb.watchedThread', $this->selectedData)) $queue[] = 'com.woltlab.wbb.watchedThread';
			if (in_array('com.woltlab.wbb.poll', $this->selectedData)) {
				$queue[] = 'com.woltlab.wbb.poll';
				$queue[] = 'com.woltlab.wbb.poll.option.vote';
			}
			if (in_array('com.woltlab.wbb.like', $this->selectedData)) $queue[] = 'com.woltlab.wbb.like';
		}
		
		return $queue;
	}
	
	/**
	 * Counts users.
	 */
	public function countUsers() {
		return $this->__getMaxID($this->databasePrefix."core_members", 'member_id');
	}
	
	/**
	 * Exports users.
	 */
	public function exportUsers($offset, $limit) {
		// cache profile fields
		$profileFields = array();
		$sql = "SELECT	*
			FROM	".$this->databasePrefix."core_pfields_data";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$profileFields[] = $row;
		}
		
		// prepare password update
		$sql = "UPDATE	wcf".WCF_N."_user
			SET	password = ?
			WHERE	userID = ?";
		$passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);
		
		// get users
		$sql = "SELECT		pfields_content.*, members.*
			FROM		".$this->databasePrefix."core_members members
			LEFT JOIN	".$this->databasePrefix."core_pfields_content pfields_content
			ON		(pfields_content.member_id = members.member_id)
			WHERE		members.member_id BETWEEN ? AND ?
			ORDER BY	members.member_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			$data = array(
				'username' => $row['name'],
				'password' => '',
				'email' => $row['email'],
				'registrationDate' => $row['joined'],
				'banned' => ($row['temp_ban'] == -1 ? 1 : 0),
				'registrationIpAddress' => UserUtil::convertIPv4To6($row['ip_address']),
				'enableGravatar' => ((!empty($row['pp_gravatar']) && $row['pp_gravatar'] == $row['email']) ? 1 : 0),
				'signature' => self::fixMessage($row['signature']),
				'profileHits' => $row['members_profile_views'],
				'userTitle' => ($row['member_title'] ?: ''),
				'lastActivityTime' => $row['last_activity']
			);
			
			// get group ids
			$groupIDs = preg_split('/,/', $row['mgroup_others'], -1, PREG_SPLIT_NO_EMPTY);
			$groupIDs[] = $row['member_group_id'];
			
			// get user options
			$options = array();
			
			// get birthday
			if ($row['bday_day'] && $row['bday_month'] && $row['bday_year']) {
				$options['birthday'] = $row['bday_year'].'-'.($row['bday_month'] < 10 ? '0' : '').$row['bday_month'].'-'.($row['bday_day'] < 10 ? '0' : '').$row['bday_day'];
			}
			
			$additionalData = array(
				'groupIDs' => $groupIDs,
				'options' => $options
			);
				
			// handle user options
			foreach ($profileFields as $profileField) {
				if (!empty($row['field_'.$profileField['pf_id']])) {
					$additionalData['options'][$profileField['pf_id']] = $row['field_'.$profileField['pf_id']];
				}
			}
				
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['member_id'], $data, $additionalData);
				
			// update password hash
			if ($newUserID) {
				$passwordUpdateStatement->execute(array('ipb3:'.$row['members_pass_hash'].':'.$row['members_pass_salt'], $newUserID));
			}
		}
	}
	
	/**
	 * Counts user options.
	 */
	public function countUserOptions() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."core_pfields_data";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user options.
	 */
	public function exportUserOptions($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."core_pfields_data
			ORDER BY	pf_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.option')->import($row['pf_id'], array(
				'categoryName' => 'profile.personal',
				'optionType' => 'textarea',
				'askDuringRegistration' => $row['pf_show_on_reg'],
			), array('name' => $this->getLanguageVar('core_pfield', $row['pf_id']))); 
		}
	}
	
	/**
	 * Counts user groups.
	 */
	public function countUserGroups() {
		return $this->__getMaxID($this->databasePrefix."core_groups", 'g_id');
	}
	
	/**
	 * Exports user groups.
	 */
	public function exportUserGroups($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."core_groups
			WHERE		g_id BETWEEN ? AND ?
			ORDER BY	g_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			$groupType = UserGroup::OTHER;
			switch ($row['g_id']) {
				case 2: // guests
					$groupType = UserGroup::GUESTS;
					break;
				case 3: // users
					$groupType = UserGroup::USERS;
					break;
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['g_id'], array(
				'groupName' => $this->getLanguageVar('core_group', $row['g_id']),
				'groupType' => $groupType,
				'userOnlineMarking' => (!empty($row['prefix']) ? ($row['prefix'].'%s'.$row['suffix']) : '%s')
			));
		}
	}
	
	/**
	 * Counts user avatars.
	 */
	public function countUserAvatars() {
		$sql = "SELECT	MAX(member_id) AS maxID
			FROM	".$this->databasePrefix."core_members
			WHERE	pp_main_photo <> ''";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		if ($row !== false) return $row['maxID'];
		return 0;
	}
	
	/**
	 * Exports user avatars.
	 */
	public function exportUserAvatars($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."core_members
			WHERE		member_id BETWEEN ? AND ?
					AND pp_main_photo <> ''
			ORDER BY	member_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			$avatarName = basename($row['pp_main_photo']);
			$source = $this->fileSystemPath.'uploads/'.$row['pp_main_photo'];
			$avatarExtension = pathinfo($avatarName, PATHINFO_EXTENSION);
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import($row['member_id'], array(
				'avatarName' => $avatarName,
				'avatarExtension' => $avatarExtension,
				'userID' => $row['member_id']
			), array('fileLocation' => $source));
		}
	}
	
	/**
	 * Counts status updates.
	 */
	public function countStatusUpdates() {
		return $this->__getMaxID($this->databasePrefix."core_member_status_updates", 'status_id');
	}
	
	/**
	 * Exports status updates.
	 */
	public function exportStatusUpdates($offset, $limit) {
		$sql = "SELECT		status_updates.*, members.name
			FROM		".$this->databasePrefix."core_member_status_updates status_updates
			LEFT JOIN	".$this->databasePrefix."core_members members
			ON		(members.member_id = status_updates.status_author_id)
			WHERE		status_updates.status_id BETWEEN ? AND ?
			ORDER BY	status_updates.status_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.comment')->import($row['status_id'], array(
				'objectID' => $row['status_member_id'],
				'userID' => $row['status_author_id'],
				'username' => ($row['name'] ?: ''),
				'message' => self::fixMessage($row['status_content']),
				'time' => $row['status_date']
			));
		}
	}
	
	/**
	 * Counts status replies.
	 */
	public function countStatusReplies() {
		return $this->__getMaxID($this->databasePrefix."core_member_status_replies", 'reply_id');
	}
	
	/**
	 * Exports status replies.
	 */
	public function exportStatusReplies($offset, $limit) {
		$sql = "SELECT		member_status_replies.*, members.name
			FROM		".$this->databasePrefix."core_member_status_replies member_status_replies
			LEFT JOIN	".$this->databasePrefix."core_members members
			ON		(members.member_id = member_status_replies.reply_member_id)
			WHERE		member_status_replies.reply_id BETWEEN ? AND ?
			ORDER BY	member_status_replies.reply_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.comment.response')->import($row['reply_id'], array(
				'commentID' => $row['reply_status_id'],
				'time' => $row['reply_date'],
				'userID' => $row['reply_member_id'],
				'username' => ($row['name'] ?: ''),
				'message' => self::fixMessage($row['reply_content']),
			));
		}
	}
	
	/**
	 * Counts followers.
	 */
	public function countFollowers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."core_follow
			WHERE	follow_app = ?
				AND follow_area = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('core', 'member'));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports followers.
	 */
	public function exportFollowers($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."core_follow
			WHERE		follow_app = ?
					AND follow_area = ?
			ORDER BY	follow_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset); 
		$statement->execute(array('core', 'member'));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.follower')->import(0, array(
				'userID' => $row['follow_member_id'],
				'followUserID' => $row['follow_rel_id'],
				'time' => $row['follow_added']
			));
		}
	}
	
	/**
	 * Counts conversations.
	 */
	public function countConversations() {
		return $this->__getMaxID($this->databasePrefix."core_message_topics", 'mt_id');
	}
	
	/**
	 * Exports conversations.
	 */
	public function exportConversations($offset, $limit) {
		$sql = "SELECT		message_topics.*, members.name
			FROM		".$this->databasePrefix."core_message_topics message_topics
			LEFT JOIN	".$this->databasePrefix."core_members members
			ON		(members.member_id = message_topics.mt_starter_id)
			WHERE		message_topics.mt_id BETWEEN ? AND ?
			ORDER BY	message_topics.mt_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation')->import($row['mt_id'], array(
				'subject' => $row['mt_title'],
				'time' => $row['mt_date'],
				'userID' => ($row['mt_starter_id'] ?: null),
				'username' => ($row['mt_is_system'] ? 'System' : ($row['name'] ?: '')),
				'isDraft' => $row['mt_is_draft']
			));
		}
	}
	
	/**
	 * Counts conversation messages.
	 */
	public function countConversationMessages() {
		return $this->__getMaxID($this->databasePrefix."core_message_posts", 'msg_id');
	}
	
	/**
	 * Exports conversation messages.
	 */
	public function exportConversationMessages($offset, $limit) {
		$sql = "SELECT		message_posts.*, members.name
			FROM		".$this->databasePrefix."core_message_posts message_posts
			LEFT JOIN	".$this->databasePrefix."core_members members
			ON		(members.member_id = message_posts.msg_author_id)
			WHERE		message_posts.msg_id BETWEEN ? AND ?
			ORDER BY	message_posts.msg_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.message')->import($row['msg_id'], array(
				'conversationID' => $row['msg_topic_id'],
				'userID' => ($row['msg_author_id'] ?: null),
				'username' => ($row['name'] ?: ''),
				'message' => self::fixMessage($row['msg_post']),
				'time' => $row['msg_date']
			));
		}
	}
	
	/**
	 * Counts conversation recipients.
	 */
	public function countConversationUsers() {
		return $this->__getMaxID($this->databasePrefix."core_message_topic_user_map", 'map_id');
	}
	
	/**
	 * Exports conversation recipients.
	 */
	public function exportConversationUsers($offset, $limit) {
		$sql = "SELECT		message_topic_user_map.*, members.name
			FROM		".$this->databasePrefix."core_message_topic_user_map message_topic_user_map
			LEFT JOIN	".$this->databasePrefix."core_members members
			ON		(members.member_id = message_topic_user_map.map_user_id)
			WHERE		message_topic_user_map.map_id BETWEEN ? AND ?
			ORDER BY	message_topic_user_map.map_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, array(
				'conversationID' => $row['map_topic_id'],
				'participantID' => $row['map_user_id'],
				'username' => ($row['name'] ?: ''),
				'hideConversation' => ($row['map_left_time'] ? 1 : 0),
				'isInvisible' => 0,
				'lastVisitTime' => $row['map_read_time']
			));
		}
	}
	
	/**
	 * Counts conversation attachments.
	 */
	public function countConversationAttachments() {
		return $this->countAttachments('core_Messaging');
	}
	
	/**
	 * Exports conversation attachments.
	 */
	public function exportConversationAttachments($offset, $limit) {
		$this->exportAttachments('core_Messaging', 'com.woltlab.wcf.conversation.attachment', $offset, $limit);
	}
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."forums_forums";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return ($row['count'] ? 1 : 0);
	}
	
	/**
	 * Exports boards.
	 */
	public function exportBoards($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."forums_forums
			ORDER BY	parent_id, id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$this->boardCache[$row['parent_id']][] = $row;
		}
		
		$this->exportBoardsRecursively();
	}
	
	/**
	 * Exports the boards recursively.
	 */
	protected function exportBoardsRecursively($parentID = -1) {
		if (!isset($this->boardCache[$parentID])) return;
		
		foreach ($this->boardCache[$parentID] as $board) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['id'], array(
				'parentID' => ($board['parent_id'] != -1 ? $board['parent_id'] : null),
				'position' => $board['position'],
				'boardType' => ($board['redirect_on'] ? Board::TYPE_LINK : ($board['sub_can_post'] ? Board::TYPE_BOARD : Board::TYPE_CATEGORY)),
				'title' => $this->getLanguageVar('forums_forum', $board['id']),
				'description' => $this->getLanguageVar('forums_forum', $board['id'], 'desc'),
				'descriptionUseHtml' => 1,
				'externalURL' => ($board['redirect_url'] ?: ''),
				'countUserPosts' => $board['inc_postcount'],
				'clicks' => $board['redirect_hits'],
				'posts' => $board['posts'],
				'threads' => $board['topics']
			));
				
			$this->exportBoardsRecursively($board['id']);
		}
	}
	
	/**
	 * Counts threads.
	 */
	public function countThreads() {
		return $this->__getMaxID($this->databasePrefix."forums_topics", 'tid');
	}
	
	/**
	 * Exports threads.
	 */
	public function exportThreads($offset, $limit) {
		// get thread ids
		$threadIDs = array();
		$sql = "SELECT		tid
			FROM		".$this->databasePrefix."forums_topics
			WHERE		tid BETWEEN ? AND ?
			ORDER BY	tid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			$threadIDs[] = $row['tid'];
		}
		
		// get tags
		$tags = $this->getTags('forums', 'forums', $threadIDs);
				
		// get threads
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('topics.tid IN (?)', array($threadIDs));
		
		$sql = "SELECT		topics.*
			FROM		".$this->databasePrefix."forums_topics topics
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			$data = array(
				'boardID' => $row['forum_id'],
				'topic' => $row['title'],
				'time' => $row['start_date'],
				'userID' => $row['starter_id'],
				'username' => $row['starter_name'],
				'views' => $row['views'],
				'isSticky' => $row['pinned'],
				'isDisabled' => ($row['approved'] == 0 ? 1 : 0),
				'isClosed' => ($row['state'] == 'close' ? 1 : 0),
				'movedThreadID' => ($row['moved_to'] ? intval($row['moved_to']) : null),
				'movedTime' => $row['moved_on'],
				'lastPostTime' => $row['last_post']
			);
			$additionalData = array();
			if (isset($tags[$row['tid']])) $additionalData['tags'] = $tags[$row['tid']];
				
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['tid'], $data, $additionalData);
		}
	}
	
	/**
	 * Counts posts.
	 */
	public function countPosts() {
		return $this->__getMaxID($this->databasePrefix."forums_posts", 'pid');
	}
	
	/**
	 * Exports posts.
	 */
	public function exportPosts($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."forums_posts
			WHERE		pid BETWEEN ? AND ?
			ORDER BY	pid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['pid'], array(
				'threadID' => $row['topic_id'],
				'userID' => $row['author_id'],
				'username' => $row['author_name'],
				'message' => self::fixMessage($row['post']),
				'time' => $row['post_date'],
				'isDeleted' => ($row['queued'] == 3 ? 1 : 0),
				'isDisabled' => ($row['queued'] == 2 ? 1 : 0),
				'lastEditTime' => ($row['edit_time'] ?: 0),
				'editorID' => null,
				'editReason' => $row['post_edit_reason'],
				'ipAddress' => UserUtil::convertIPv4To6($row['ip_address']),
				'deleteTime' => $row['pdelete_time']
			));
		}
	}
	
	/**
	 * Counts watched threads.
	 */
	public function countWatchedThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."core_follow
			WHERE	follow_app = ?
				AND follow_area = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('forums', 'topic'));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports watched threads.
	 */
	public function exportWatchedThreads($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."core_follow
			WHERE		follow_app = ?
					AND follow_area = ?
			ORDER BY	follow_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset); 
		$statement->execute(array('forums', 'topic'));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.watchedThread')->import(0, array(
				'objectID' => $row['follow_rel_id'],
				'userID' => $row['follow_member_id']
			));
		}
	}
	
	/**
	 * Counts polls.
	 */
	public function countPolls() {
		return $this->__getMaxID($this->databasePrefix."core_polls", 'pid');
	}
	
	/**
	 * Exports polls.
	 */
	public function exportPolls($offset, $limit) {
		$sql = "SELECT		polls.*, topics.topic_firstpost
			FROM		".$this->databasePrefix."core_polls polls
			LEFT JOIN	".$this->databasePrefix."forums_topics topics
			ON		(topics.tid = polls.tid)
			WHERE		pid BETWEEN ? AND ?
			ORDER BY	pid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			$data = @unserialize($row['choices']);
			if (!$data || !isset($data[1])) continue; 

			// import poll
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll')->import($row['pid'], array(
				'objectID' => $row['topic_firstpost'],
				'question' => $data[1]['question'],
				'time' => $row['start_date'],
				'isPublic' => $row['poll_view_voters'],
				'maxVotes' => (!empty($data[1]['multi']) ? count($data[1]['choice']) : 1),
				'votes' => $row['votes']
			));
			
			// import poll options
			foreach ($data[1]['choice'] as $key => $choice) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option')->import($row['pid'].'-'.$key, array(
					'pollID' => $row['pid'],
					'optionValue' => $choice,
					'showOrder' => $key,
					'votes' => $data[1]['votes'][$key]
				));
			}
		}
	}
	
	/**
	 * Counts poll option votes.
	 */
	public function countPollOptionVotes() {
		return $this->__getMaxID($this->databasePrefix."core_voters", 'vid');
	}
	
	/**
	 * Exports poll option votes.
	 */
	public function exportPollOptionVotes($offset, $limit) {
		$sql = "SELECT		polls.*, voters.*
			FROM		".$this->databasePrefix."core_voters voters
			LEFT JOIN	".$this->databasePrefix."core_polls polls
			ON		(polls.tid = voters.tid)
			WHERE		voters.vid BETWEEN ? AND ?
			ORDER BY	voters.vid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			$data = @unserialize($row['member_choices']);
			if (!$data || !isset($data[1])) continue;
			
			foreach ($data[1] as $pollOptionKey) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option.vote')->import(0, array(
					'pollID' => $row['pid'],
					'optionID' => $row['pid'].'-'.$pollOptionKey,
					'userID' => $row['member_id']
				));
			}
		}
	}
	
	/**
	 * Counts likes.
	 */
	public function countLikes() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."core_reputation_index
			WHERE	app = ?
				AND type = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('forums', 'pid'));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports likes.
	 */
	public function exportLikes($offset, $limit) {
		$sql = "SELECT		core_reputation_index.*, forums_posts.author_id
			FROM		".$this->databasePrefix."core_reputation_index core_reputation_index
			LEFT JOIN	".$this->databasePrefix."forums_posts forums_posts
			ON		(forums_posts.pid = core_reputation_index.type_id)
			WHERE		core_reputation_index.app = ?
					AND core_reputation_index.type = ?
			ORDER BY	core_reputation_index.id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('forums', 'pid'));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.like')->import(0, array(
				'objectID' => $row['type_id'],
				'objectUserID' => ($row['author_id'] ?: null),
				'userID' => $row['member_id'],
				'likeValue' => Like::LIKE,
				'time' => $row['rep_date']
			));
		}
	}
	
	/**
	 * Counts post attachments.
	 */
	public function countPostAttachments() {
		return $this->countAttachments('forums_Forums');
	}
	
	/**
	 * Exports post attachments.
	 */
	public function exportPostAttachments($offset, $limit) {
		$this->exportAttachments('forums_Forums', 'com.woltlab.wbb.attachment', $offset, $limit);
	}
	
	private function countAttachments($type) {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."core_attachments_map
			WHERE	location_key = ?
				AND id2 IS NOT NULL";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($type));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	private function exportAttachments($type, $objectType, $offset, $limit) {
		$sql = "SELECT		core_attachments.*, core_attachments_map.id2
			FROM		".$this->databasePrefix."core_attachments_map core_attachments_map
			LEFT JOIN	".$this->databasePrefix."core_attachments core_attachments
			ON		(core_attachments.attach_id = core_attachments_map.attachment_id)	
			WHERE		core_attachments_map.location_key = ?
					AND core_attachments_map.id2 IS NOT NULL
			ORDER BY	core_attachments_map.attachment_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array($type));
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath.'uploads/'.$row['attach_location'];

			ImportHandler::getInstance()->getImporter($objectType)->import($row['attach_id'], array(
				'objectID' => $row['id2'],
				'userID' => ($row['attach_member_id'] ?: null),
				'filename' => $row['attach_file'],
				'filesize' => $row['attach_filesize'],
				'isImage' => $row['attach_is_image'],
				'downloads' => $row['attach_hits'],
				'uploadTime' => $row['attach_date'],
			), array('fileLocation' => $fileLocation));
		}
	}
	
	private function getTags($app, $area, array $objectIDs) {
		$tags = array();
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('tag_meta_app = ?', array($app));
		$conditionBuilder->add('tag_meta_area = ?', array($area));
		$conditionBuilder->add('tag_meta_id IN (?)', array($objectIDs));
		
		// get taggable id
		$sql = "SELECT		tag_meta_id, tag_text
			FROM		".$this->databasePrefix."core_tags
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			if (!isset($tags[$row['tag_meta_id']])) $tags[$row['tag_meta_id']] = array();
			$tags[$row['tag_meta_id']][] = $row['tag_text'];
		}
		
		return $tags;
	}
	
	
	private function getDefaultLanguageID() {
		if ($this->defaultLanguageID === null) {
			$sql = "SELECT	lang_id
				FROM	".$this->databasePrefix."core_sys_lang
				WHERE	lang_default = ?";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute(array(1));
			$row = $statement->fetchArray();
			if ($row !== false) {
				$this->defaultLanguageID = $row['lang_id'];
			}
			else {
				$this->defaultLanguageID = 0;
			}
		}
		
		return $this->defaultLanguageID;
		
	}
	
	private function getLanguageVar($prefix, $id, $suffix = '') {
		if ($this->languageStatement === null) {
			$sql = "SELECT	word_custom
				FROM	".$this->databasePrefix."core_sys_lang_words
				WHERE	lang_id = ?
					AND word_key = ?";
			$this->languageStatement = $this->database->prepareStatement($sql, 1);
		}
		$this->languageStatement->execute(array($this->getDefaultLanguageID(), $prefix . '_' . $id . ($suffix ? ('_' . $suffix) : '')));
		$row = $this->languageStatement->fetchArray();
		if ($row !== false) {
			return $row['word_custom'];
		}
		
		return '';
	}
	
	private static function fixMessage($string) {
		// align
		$string = preg_replace('~<p style="text-align:(left|center|right);">(.*?)</p>~is', "[align=\\1]\\2[/align]\n\n", $string);
		
		// <p> to newline
		$string = str_ireplace('<p>', "", $string);
		$string = str_ireplace('</p>', "\n\n", $string);
		$string = str_ireplace('<br>', "\n", $string);
		
		// strike
		$string = str_ireplace('<s>', '[s]', $string);
		$string = str_ireplace('</s>', '[/s]', $string);
		
		// super
		$string = str_ireplace('<sup>', '[sup]', $string);
		$string = str_ireplace('</sup>', '[/sup]', $string);
		
		// subscript
		$string = str_ireplace('<sub>', '[sub]', $string);
		$string = str_ireplace('</sub>', '[/sub]', $string);
		
		// bold
		$string = str_ireplace('<strong>', '[b]', $string);
		$string = str_ireplace('</strong>', '[/b]', $string);
		$string = str_ireplace('<b>', '[b]', $string);
		$string = str_ireplace('</b>', '[/b]', $string);
		
		// italic
		$string = str_ireplace('<em>', '[i]', $string);
		$string = str_ireplace('</em>', '[/i]', $string);
		$string = str_ireplace('<i>', '[i]', $string);
		$string = str_ireplace('</i>', '[/i]', $string);
		
		// underline
		$string = str_ireplace('<u>', '[u]', $string);
		$string = str_ireplace('</u>', '[/u]', $string);
		
		// font color
		$string = preg_replace('~<span style="color:(.*?);?">(.*?)</span>~is', '[color=\\1]\\2[/color]', $string);
		
		// font size
		$string = preg_replace('~<span style="font-size:(\d+)px;">(.*?)</span>~is', '[size=\\1]\\2[/size]', $string);
		
		// font face
		$string = preg_replace_callback('~<span style="font-family:(.*?)">(.*?)</span>~is', function ($matches) {
			return "[font='".str_replace(";", '', str_replace("'", '', $matches[1]))."']".$matches[2]."[/font]";
		}, $string);
		
		// embedded attachments
		$string = preg_replace('~<a class="ipsAttachLink" (?:rel="[^"]*" )?href="[^"]*id=(\d+)[^"]*".*?</a>~i', '[attach]\\1[/attach]', $string);
		$string = preg_replace('~<a.*?><img data-fileid="(\d+)".*?</a>~i', '[attach]\\1[/attach]', $string);
			
		// urls
		$string = preg_replace('~<a.*?href=(?:"|\')mailto:([^"]*)(?:"|\')>(.*?)</a>~is', '[email=\'\\1\']\\2[/email]', $string);
		$string = preg_replace('~<a.*?href=(?:"|\')([^"]*)(?:"|\')>(.*?)</a>~is', '[url=\'\\1\']\\2[/url]', $string);
		
		// quotes
		$string = preg_replace('~<blockquote[^>]*>(.*?)</blockquote>~is', '[quote]\\1[/quote]', $string);
		
		// code
		$string = preg_replace('~<pre[^>]*>(.*?)</pre>~is', '[code]\\1[/code]', $string);
		
		// smileys
		$string = preg_replace('~<img title="([^"]*)" alt="[^"]*" src="<fileStore.core_Emoticons>[^"]*">~is', '\\1', $string);
		$string = preg_replace('~<img src="<fileStore.core_Emoticons>[^"]*" alt="[^"]*" title="([^"]*)">~is', '\\1', $string);
		
		// list
		$string = str_ireplace('</ol>', '[/list]', $string);
		$string = str_ireplace('</ul>', '[/list]', $string);
		$string = str_ireplace('<ul>', '[list]', $string);
		$string = str_ireplace("<ol type='1'>", '[list=1]', $string);
		$string = str_ireplace("<ol>", '[list=1]', $string);
		$string = str_ireplace('<li>', '[*]', $string);
		$string = str_ireplace('</li>', '', $string);
		
		// images
		$string = preg_replace('~<img[^>]+src=["\']([^"\']+)["\'][^>]*/?>~is', '[img]\\1[/img]', $string);
		
		// strip tags
		$string = StringUtil::stripHTML($string);
		
		// decode html entities
		$string = StringUtil::decodeHTML($string);
		
		return StringUtil::trim($string);
	}
}
