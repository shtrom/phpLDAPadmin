<?php
// $Header$

/**
 * Classes and functions for the LDAP tree.
 *
 * @author The phpLDAPadmin development team
 * @package phpLDAPadmin
 */

/**
 * Abstract class which represents the LDAP tree view ; the draw() method
 * must be implemented by subclasses
 *
 * @package phpLDAPadmin
 * @subpackage Tree
 * @see HTMLTree AJAXTree
 */
abstract class Tree {
	# Server that this tree represents
	private $server_id = null;
	# List of entries in the tree view cache
	protected $entries = array();

	/**
	 * Displays the LDAP tree
	 */
	abstract public function draw();

	protected function __construct($server_id) {
		$this->server_id = $server_id;
	}

	/**
	 * Create an instance of the tree - this is used when we call this class directly
	 * Tree::getInstance($index)
	 *
	 * @return object Tree
	 */
	static public function getInstance($server_id) {
		$tree = get_cached_item($server_id,'tree');

		if (! $tree) {
			$server = $_SESSION[APPCONFIG]->getServer($server_id);

			if (! $server)
				return null;

			$treeclass = $_SESSION[APPCONFIG]->getValue('appearance','tree');
			eval('$tree = new '.$treeclass.'($server_id);');

			# If we are not logged in, just return the empty tree.
			if (is_null($server->getLogin(null)))
				return $tree;

			foreach ($server->getBaseDN(null) as $base)
				if ($base)
					$tree->addEntry($base);

			set_cached_item($server_id,'tree','null',$tree);
		}

		return $tree;
	}

	/**
	 * Get the Server ID for this tree
	 *
	 * @return int Server ID that this tree is for
	 */
	protected function getServerID() {
		return $this->server_id;
	}

	/**
	 * Get the server Object for this tree
	 *
	 * @return object Server Object for this tree
	 */
	protected function getServer() {
		return $_SESSION[APPCONFIG]->getServer($this->server_id);
	}

	/**
	 * Get the entries that are BaseDN entries.
	 *
	 * @return array Base DN entries
	 */
	public function getBaseEntries() {
		$return = array();

		foreach ($this->entries as $details)
			if ($details->isBaseDN())
				array_push($return,$details);

		return $return;
	}

	/**
	 * This function will take the DN, convert it to lowercase and strip unnessary
	 * commas. This result will be used as the index for the tree object.
	 * Any display of a DN should use the object->dn entry, not the index.
	 * The reason we need to do this is because:
	 * uid=User A,ou=People,c=AU and
	 * uid=User B, ou=PeOpLe, c=au
	 * are infact in the same branch, but PLA will show them inconsistently.
	 *
	 * @param dn DN to clean
	 * @return dn Lowercase clean DN
	 */
	private function indexDN($dn) {
		$index = strtolower(implode(',',pla_explode_dn($dn)));

		if (DEBUG_ENABLED)
			debug_log('Entered with (%s), Result (%s)',1,__FILE__,__LINE__,__METHOD__,$dn,$index);

		return $index;
	}

	/**
	 * Get a tree entry
	 *
	 * @param dn DN to retrieve
	 * @return object Tree DN object
	 */
	public function getEntry($dn) {
		$dnlower = $this->indexDN($dn);

		if (isset($this->entries[$dnlower]))
			return $this->entries[$dnlower];
		else
			return null;
	}

	/**
	 * Add an entry in the tree view ; the entry is added in the
	 * children array of its parent
	 *
	 * @param dn DN to add
	 * @param string $dn the dn of the entry to create
	 */
	public function addEntry($dn) {
		if (DEBUG_ENABLED)
			debug_log('Entered with (%s)',1,__FILE__,__LINE__,__METHOD__,$dn);

		$server = $this->getServer();
		$dnlower = $this->indexDN($dn);

		if (! ($server->dnExists($dn)))
			return;

		if (isset($this->entries[$dnlower]))
			debug_dump_backtrace('Calling add entry to an entry that ALREADY exists?',1);

		if (DEBUG_ENABLED)
			debug_log('New ENTRY (%s).',64,__FILE__,__LINE__,__METHOD__,$dn);

		$tree_factory = new TreeItem($server->getIndex(),$dn);
		$tree_factory->setObjectClasses($server->getDNAttrValue($dn,'objectClass'));

		$this->entries[$dnlower] = $tree_factory;

		# Is this entry in a base entry?
		if (in_array_ignore_case($dn,$server->getBaseDN(null))) {
			$this->entries[$dnlower]->setBase();

		# If the parent entry is not in the tree, we add it. This routine will in itself
		# recall this method until we get to the top of the tree (the base).
		} else {
			$parent_dn = $server->getContainer($dn);

			if (DEBUG_ENABLED)
				debug_log('Parent DNs (%s)',64,__FILE__,__LINE__,__METHOD__,$parent_dn);

			if ($parent_dn) {
				$parent_entry = $this->getEntry($parent_dn);

				if (! $parent_entry) {
					$this->addEntry($parent_dn);
					$parent_entry = $this->getEntry($parent_dn);
				}

				# Update this DN's parent's children list as well.
				$parent_entry->addChild($dn);
			}
		}
	}

	/**
	 * Delete an entry from the tree view ; the entry is deleted from the
	 * children array of its parent
	 *
	 * @param dn DN to remote
	 */
	public function delEntry($dn) {
		if (DEBUG_ENABLED)
			debug_log('Entered with (%s)',1,__FILE__,__LINE__,__METHOD__,$dn);

		$server = $this->getServer();
		$dnlower = $this->indexDN($dn);

		if (isset($this->entries[$dnlower]))
			unset($this->entries[$dnlower]);

		# Delete entry from parent's children as well.
		$parent_dn = $server->getContainer($dn);
		$parent_entry = $this->getEntry($parent_dn);

		if ($parent_entry)
			$parent_entry->delChild($dn);
	}

	/**
	 * Rename an entry in the tree
	 *
	 * @param dn Old DN
	 * @param dn New DN
	 */
	public function renameEntry($dnOLD,$dnNEW) {
		if (DEBUG_ENABLED)
			debug_log('Entered with (%s,%s)',1,__FILE__,__LINE__,__METHOD__,$dnOLD,$dnNEW);

		$server = $this->getServer();
		$dnlowerOLD = $this->indexDN($dnOLD);
		$dnlowerNEW = $this->indexDN($dnNEW);

		$this->entries[$dnlowerNEW] = $this->entries[$dnlowerOLD];
		if ($dnlowerOLD != $dnlowerNEW)
			unset($this->entries[$dnlowerOLD]);
		$this->entries[$dnlowerNEW]->rename($dnNEW);

		# Update the parent's children
		$parentNEW = $server->getContainer($dnNEW);
		$parentOLD = $server->getContainer($dnOLD);

		$parent_entry = $this->getEntry($parentNEW);
		if ($parent_entry)
			$parent_entry->addChild($dnNEW);

		$parent_entry = $this->getEntry($parentOLD);
		if ($parent_entry)
			$parent_entry->delChild($dnOLD);
	}

	/**
	 * Read the children of a tree entry
	 *
	 * @param dn DN of the entry
	 * @param boolean LDAP Size Limit
	 */
	public function readChildren($dn,$nolimit=false) {
		if (DEBUG_ENABLED)
			debug_log('Entered with (%s,%s)',1,__FILE__,__LINE__,__METHOD__,$dn,$nolimit);

		$server = $this->getServer();
		$dnlower = $this->indexDN($dn);

		if (! isset($this->entries[$dnlower]))
			debug_dump_backtrace('Reading children on an entry that isnt set? '.$dnlower,true);

		$ldap['child_limit'] = $nolimit ? 0 : $_SESSION[APPCONFIG]->getValue('search','size_limit');
		$ldap['filter'] = $_SESSION[APPCONFIG]->getValue('appearance','tree_filter');
		$ldap['deref'] = $_SESSION[APPCONFIG]->getValue('deref','tree');

		# Perform the query to get the children.
		$ldap['children'] = $server->getContainerContents($dn,null,$ldap['child_limit'],$ldap['filter'],$ldap['deref']);

		if (! count($ldap['children'])) {
			$this->entries[$dnlower]->unsetSizeLimited();

			return;
		}

		if (DEBUG_ENABLED)
			debug_log('Children of (%s) are (%s)',64,__FILE__,__LINE__,__METHOD__,$dn,$ldap['children']);

		# Relax our execution time, it might take some time to load this
		if ($nolimit)
			@set_time_limit($_SESSION[APPCONFIG]->getValue('search','time_limit'));

		foreach ($ldap['children'] as $child) {
			if (DEBUG_ENABLED)
				debug_log('Adding (%s)',64,__FILE__,__LINE__,__METHOD__,$child);

			if (! in_array($child,$this->entries[$dnlower]->getChildren()))
				$this->entries[$dnlower]->addChild($child);
		}

		if (count($this->entries[$dnlower]->getChildren()) == $ldap['child_limit'])
			$this->entries[$dnlower]->setSizeLimited();
		else
			$this->entries[$dnlower]->unsetSizeLimited();

		if (DEBUG_ENABLED)
			debug_log('Entered with (%s,%s), Returning ()',1,__FILE__,__LINE__,__METHOD__,$dn,$nolimit);
	}

	/**
	 * Return the number of children an entry has. Optionally autoread the child entry.
	 *
	 * @param dn DN of the entry
	 * @param boolean LDAP Size Limit
	 */
	protected function readChildrenNumber($dn,$nolimit=false) {
		if (DEBUG_ENABLED)
			debug_log('Entered with (%s,%s)',1,__FILE__,__LINE__,__METHOD__,$dn,$nolimit);

		$dnlower = $this->indexDN($dn);

		if (! isset($this->entries[$dnlower]))
			debug_dump_backtrace('Reading children on an entry that isnt set?',true);

		# Read the entry if we havent got it yet.
		if (! $this->entries[$dnlower]->isLeaf() && ! $this->entries[$dnlower]->getChildren())
			$this->readChildren($dn,$nolimit);

		return count($this->entries[$dnlower]->getChildren());
	}
}
?>
