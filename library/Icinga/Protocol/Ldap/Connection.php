<?php
/* Icinga Web 2 | (c) 2013-2015 Icinga Development Team | GPLv2+ */

namespace Icinga\Protocol\Ldap;

use ArrayIterator;
use Icinga\Application\Config;
use Icinga\Application\Logger;
use Icinga\Application\Platform;
use Icinga\Data\ConfigObject;
use Icinga\Data\Selectable;
use Icinga\Data\Sortable;
use Icinga\Exception\ProgrammingError;
use Icinga\Protocol\Ldap\Exception as LdapException;

/**
 * Encapsulate LDAP connections and query creation
 */
class Connection implements Selectable
{
    /**
     * Indicates that the target object cannot be found
     *
     * @var int
     */
    const LDAP_NO_SUCH_OBJECT = 32;

    /**
     * Indicates that in a search operation, the size limit specified by the client or the server has been exceeded
     *
     * @var int
     */
    const LDAP_SIZELIMIT_EXCEEDED = 4;

    /**
     * Indicates that an LDAP server limit set by an administrative authority has been exceeded
     *
     * @var int
     */
    const LDAP_ADMINLIMIT_EXCEEDED = 11;

    /**
     * Indicates that during a bind operation one of the following occurred: The client passed either an incorrect DN
     * or password, or the password is incorrect because it has expired, intruder detection has locked the account, or
     * another similar reason.
     *
     * @var int
     */
    const LDAP_INVALID_CREDENTIALS = 49;

    /**
     * The default page size to use for paged queries
     *
     * @var int
     */
    const PAGE_SIZE = 1000;

    /**
     * Encrypt connection using STARTTLS (upgrading a plain text connection)
     *
     * @var string
     */
    const STARTTLS = 'starttls';

    /**
     * Encrypt connection using LDAP over SSL (using a separate port)
     *
     * @var string
     */
    const LDAPS = 'ldaps';

    /**
     * Encryption for the connection if any
     *
     * @var string
     */
    protected $encryption;

    /**
     * The LDAP link identifier being used
     *
     * @var resource
     */
    protected $ds;

    /**
     * The ip address, hostname or ldap URI being used to connect with the LDAP server
     *
     * @var string
     */
    protected $hostname;

    /**
     * The port being used to connect with the LDAP server
     *
     * @var int
     */
    protected $port;

    /**
     * The distinguished name being used to bind to the LDAP server
     *
     * @var string
     */
    protected $bindDn;

    /**
     * The password being used to bind to the LDAP server
     *
     * @var string
     */
    protected $bindPw;

    /**
     * The distinguished name being used as the base path for queries which do not provide one theirselves
     *
     * @var string
     */
    protected $rootDn;

    /**
     * Whether to load the configuration for strict certificate validation or the one for non-strict validation
     *
     * @var bool
     */
    protected $validateCertificate;

    /**
     * Whether the bind on this connection has already been performed
     *
     * @var bool
     */
    protected $bound;

    /**
     * The current connection's root node
     *
     * @var Root
     */
    protected $root;

    /**
     * The properties and capabilities of the LDAP server
     *
     * @var Capability
     */
    protected $capabilities;

    /**
     * Whether discovery was successful or not
     *
     * @var bool
     */
    protected $discoverySuccess;

    /**
     * Create a new connection object
     *
     * @param   ConfigObject    $config
     */
    public function __construct(ConfigObject $config)
    {
        $this->hostname = $config->hostname;
        $this->bindDn = $config->bind_dn;
        $this->bindPw = $config->bind_pw;
        $this->rootDn = $config->root_dn;
        $this->port = $config->get('port', 389);
        $this->validateCertificate = (bool) $config->get('reqcert', true);

        $this->encryption = $config->encryption;
        if ($this->encryption !== null) {
            $this->encryption = strtolower($this->encryption);
        }
    }

    /**
     * Return the ip address, hostname or ldap URI being used to connect with the LDAP server
     *
     * @return  string
     */
    public function getHostname()
    {
        return $this->hostname;
    }

    /**
     * Return the port being used to connect with the LDAP server
     *
     * @return  int
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * Return the distinguished name being used as the base path for queries which do not provide one theirselves
     *
     * @return  string
     */
    public function getDn()
    {
        return $this->rootDn;
    }

    /**
     * Return the root node for this connection
     *
     * @return  Root
     */
    public function root()
    {
        if ($this->root === null) {
            $this->root = Root::forConnection($this);
        }

        return $this->root;
    }

    /**
     * Return the capabilities of the current connection
     *
     * @return  Capability
     */
    public function getCapabilities()
    {
        if ($this->capabilities === null) {
            $this->connect(); // Populates $this->capabilities
        }

        return $this->capabilities;
    }

    /**
     * Return whether discovery was successful or not
     *
     * @return  bool    true if the capabilities were successfully determined, false if the capabilities were guessed
     */
    public function discoverySuccessful()
    {
        return $this->discoverySuccess;
    }

    /**
     * Establish a connection
     *
     * @throws  LdapException   In case the connection could not be established
     */
    public function connect()
    {
        if ($this->ds === null) {
            $this->ds = $this->prepareNewConnection();
        }
    }

    /**
     * Perform a LDAP bind on the current connection
     *
     * @throws  LdapException   In case the LDAP bind was unsuccessful
     */
    public function bind()
    {
        if ($this->bound) {
            return;
        }

        $success = @ldap_bind($this->ds, $this->bindDn, $this->bindPw);
        if (! $success) {
            throw new LdapException(
                'LDAP connection to %s:%s (%s / %s) failed: %s',
                $this->hostname,
                $this->port,
                $this->bindDn,
                '***' /* $this->bindPw */,
                ldap_error($this->ds)
            );
        }

        $this->bound = true;
    }

    /**
     * Provide a query on this connection
     *
     * @return  Query
     */
    public function select()
    {
        return new Query($this);
    }

    /**
     * Fetch and return all rows of the given query's result set using an iterator
     *
     * @param   Query   $query  The query returning the result set
     *
     * @return  ArrayIterator
     */
    public function query(Query $query)
    {
        return new ArrayIterator($this->fetchAll($query));
    }

    /**
     * Count all rows of the given query's result set
     *
     * @param   Query   $query  The query returning the result set
     *
     * @return  int
     */
    public function count(Query $query)
    {
        $this->connect();
        $this->bind();

        $res = $this->runQuery($query, array());
        return count($res);
    }

    /**
     * Retrieve an array containing all rows of the result set
     *
     * @param   Query   $query      The query returning the result set
     * @param   array   $fields     Request these attributes instead of the ones registered in the given query
     *
     * @return  array
     */
    public function fetchAll(Query $query, array $fields = null)
    {
        $this->connect();
        $this->bind();

        if (
            $query->getUsePagedResults()
            && version_compare(PHP_VERSION, '5.4.0') >= 0
            && $this->getCapabilities()->hasPagedResult()
        ) {
            return $this->runPagedQuery($query, $fields);
        } else {
            return $this->runQuery($query, $fields);
        }
    }

    /**
     * Fetch the first row of the result set
     *
     * @param   Query   $query      The query returning the result set
     * @param   array   $fields     Request these attributes instead of the ones registered in the given query
     *
     * @return  mixed
     */
    public function fetchRow(Query $query, array $fields = null)
    {
        $clonedQuery = clone $query;
        $clonedQuery->limit(1);
        $clonedQuery->setUsePagedResults(false);
        $results = $this->fetchAll($clonedQuery, $fields);
        return array_shift($results) ?: false;
    }

    /**
     * Fetch the first column of all rows of the result set as an array
     *
     * @param   Query   $query      The query returning the result set
     * @param   array   $fields     Request these attributes instead of the ones registered in the given query
     *
     * @return  array
     *
     * @throws  ProgrammingError    In case no attribute is being requested
     */
    public function fetchColumn(Query $query, array $fields = null)
    {
        if ($fields === null) {
            $fields = $query->getColumns();
        }

        $column = current($fields);
        if (! $column) {
            throw new ProgrammingError('You must request at least one attribute when fetching a single column');
        }

        $results = $this->fetchAll($query, array($column));
        $values = array();
        foreach ($results as $row) {
            if (isset($row->$column)) {
                $values[] = $row->$column;
            }
        }

        return $values;
    }

    /**
     * Fetch the first column of the first row of the result set
     *
     * @param   Query   $query      The query returning the result set
     * @param   array   $fields     Request these attributes instead of the ones registered in the given query
     *
     * @return  string
     */
    public function fetchOne(Query $query, array $fields = null)
    {
        $row = (array) $this->fetchRow($query, $fields);
        return array_shift($row) ?: false;
    }

    /**
     * Fetch all rows of the result set as an array of key-value pairs
     *
     * The first column is the key, the second column is the value.
     *
     * @param   Query   $query      The query returning the result set
     * @param   array   $fields     Request these attributes instead of the ones registered in the given query
     *
     * @return  array
     *
     * @throws  ProgrammingError    In case there are less than two attributes being requested
     */
    public function fetchPairs(Query $query, array $fields = null)
    {
        if ($fields === null) {
            $fields = $query->getColumns();
        }

        if (count($fields) < 2) {
            throw new ProgrammingError('You are required to request at least two attributes');
        }

        $columns = $desiredColumnNames = array();
        foreach ($fields as $alias => $column) {
            if (is_int($alias)) {
                $columns[] = $column;
                $desiredColumnNames[] = $column;
            } else {
                $columns[$alias] = $column;
                $desiredColumnNames[] = $alias;
            }

            if (count($desiredColumnNames) === 2) {
                break;
            }
        }

        $results = $this->fetchAll($query, $columns);
        $pairs = array();
        foreach ($results as $row) {
            $colOne = $desiredColumnNames[0];
            $colTwo = $desiredColumnNames[1];
            $pairs[$row->$colOne] = $row->$colTwo;
        }

        return $pairs;
    }

    /**
     * Test the given LDAP credentials by establishing a connection and attempting a LDAP bind
     *
     * @param   string  $bindDn
     * @param   string  $bindPw
     *
     * @return  bool                Whether the given credentials are valid
     *
     * @throws  LdapException       In case an error occured while establishing the connection or attempting the bind
     */
    public function testCredentials($bindDn, $bindPw)
    {
        $this->connect();

        $success = @ldap_bind($this->ds, $bindDn, $bindPw);
        if (! $success) {
            if (ldap_errno($this->ds) === self::LDAP_INVALID_CREDENTIALS) {
                Logger::debug(
                    'Testing LDAP credentials (%s / %s) failed: %s',
                    $bindDn,
                    '***',
                    ldap_error($this->ds)
                );
                return false;
            }

            throw new LdapException(ldap_error($this->ds));
        }

        return true;
    }

    /**
     * Return whether an entry identified by the given distinguished name exists
     *
     * @param   string  $dn
     *
     * @return  bool
     */
    public function hasDn($dn)
    {
        $this->connect();
        $this->bind();

        $result = ldap_read($this->ds, $dn, '(objectClass=*)', array('objectClass'));
        return ldap_count_entries($this->ds, $result) > 0;
    }

    /**
     * Delete a root entry and all of its children identified by the given distinguished name
     *
     * @param   string  $dn
     *
     * @return  bool
     *
     * @throws  LdapException   In case an error occured while deleting an entry
     */
    public function deleteRecursively($dn)
    {
        $this->connect();
        $this->bind();

        $result = @ldap_list($this->ds, $dn, '(objectClass=*)', array('objectClass'));
        if ($result === false) {
            if (ldap_errno($this->ds) === self::LDAP_NO_SUCH_OBJECT) {
                return false;
            }

            throw new LdapException('LDAP list for "%s" failed: %s', $dn, ldap_error($this->ds));
        }

        $children = ldap_get_entries($this->ds, $result);
        for ($i = 0; $i < $children['count']; $i++) {
            $result = $this->deleteRecursively($children[$i]['dn']);
            if (! $result) {
                // TODO: return result code, if delete fails
                throw new LdapException('Recursively deleting "%s" failed', $dn);
            }
        }

        return $this->deleteDn($dn);
    }

    /**
     * Delete a single entry identified by the given distinguished name
     *
     * @param   string  $dn
     *
     * @return  bool
     *
     * @throws  LdapException   In case an error occured while deleting the entry
     */
    public function deleteDn($dn)
    {
        $this->connect();
        $this->bind();

        $result = @ldap_delete($this->ds, $dn);
        if ($result === false) {
            if (ldap_errno($this->ds) === self::LDAP_NO_SUCH_OBJECT) {
                return false; // TODO: Isn't it a success if something i'd like to remove is not existing at all???
            }

            throw new LdapException('LDAP delete for "%s" failed: %s', $dn, ldap_error($this->ds));
        }

        return true;
    }

    /**
     * Fetch the distinguished name of the result of the given query
     *
     * @param   Query   $query  The query returning the result set
     *
     * @return  string          The distinguished name, or false when the given query yields no results
     *
     * @throws  LdapException   In case the query yields multiple results
     */
    public function fetchDn(Query $query)
    {
        $rows = $this->fetchAll($query, array());
        if (count($rows) > 1) {
            throw new LdapException('Cannot fetch single DN for %s', $query);
        }

        return key($rows);
    }

    /**
     *
     * Execute the given LDAP query and return the resulting entries
     *
     * @param Query $query      The query to execute
     * @param array $fields     The fields that will be fetched from the matches
     *
     * @return array            The matched entries
     * @throws LdapException
     */
    protected function runQuery(Query $query, array $fields = null)
    {
        $limit = $query->getLimit();
        $offset = $query->hasOffset() ? $query->getOffset() - 1 : 0;

        if ($fields === null) {
            $fields = $query->getColumns();
        }

        $serverSorting = false;//$this->capabilities->hasOid(Capability::LDAP_SERVER_SORT_OID);
        if ($serverSorting && $query->hasOrder()) {
            ldap_set_option($this->ds, LDAP_OPT_SERVER_CONTROLS, array(
                array(
                    'oid'   => Capability::LDAP_SERVER_SORT_OID,
                    'value' => $this->encodeSortRules($query->getOrder())
                )
            ));
        }

        $results = @ldap_search(
            $this->ds,
            $query->getBase() ?: $this->rootDn,
            (string) $query,
            array_values($fields),
            0, // Attributes and values
            $serverSorting && $limit ? $offset + $limit : 0
        );
        if ($results === false) {
            if (ldap_errno($this->ds) === self::LDAP_NO_SUCH_OBJECT) {
                return array();
            }

            throw new LdapException(
                'LDAP query "%s" (base %s) failed. Error: %s',
                $query,
                $query->getBase() ?: $this->rootDn,
                ldap_error($this->ds)
            );
        } elseif (ldap_count_entries($this->ds, $results) === 0) {
            return array();
        }

        $count = 0;
        $entries = array();
        $entry = ldap_first_entry($this->ds, $results);
        do {
            $count += 1;
            if (! $serverSorting || $offset === 0 || $offset < $count) {
                $entries[ldap_get_dn($this->ds, $entry)] = $this->cleanupAttributes(
                    ldap_get_attributes($this->ds, $entry), array_flip($fields)
                );
            }
        } while (
            (! $serverSorting || $limit === 0 || $limit !== count($entries))
            && ($entry = ldap_next_entry($this->ds, $entry))
        );

        if (! $serverSorting && $query->hasOrder()) {
            uasort($entries, array($query, 'compare'));
            if ($limit && $count > $limit) {
                $entries = array_splice($entries, $query->hasOffset() ? $query->getOffset() : 0, $limit);
            }
        }

        ldap_free_result($results);
        return $entries;
    }

    /**
     * Run the given LDAP query and return the resulting entries
     *
     * This utilizes paged search requests as defined in RFC 2696.
     *
     * @param   Query   $query      The query to fetch results with
     * @param   array   $fields     Request these attributes instead of the ones registered in the given query
     * @param   int     $pageSize   The maximum page size, defaults to self::PAGE_SIZE
     *
     * @return  array
     *
     * @throws  LdapException       In case an error occured while fetching the results
     */
    protected function runPagedQuery(Query $query, array $fields = null, $pageSize = null)
    {
        if ($pageSize === null) {
            $pageSize = static::PAGE_SIZE;
        }

        $limit = $query->getLimit();
        $offset = $query->hasOffset() ? $query->getOffset() - 1 : 0;
        $queryString = (string) $query;
        $base = $query->getBase() ?: $this->rootDn;

        if ($fields === null) {
            $fields = $query->getColumns();
        }

        $serverSorting = false;//$this->capabilities->hasOid(Capability::LDAP_SERVER_SORT_OID);
        if ($serverSorting && $query->hasOrder()) {
            ldap_set_option($this->ds, LDAP_OPT_SERVER_CONTROLS, array(
                array(
                    'oid'   => Capability::LDAP_SERVER_SORT_OID,
                    'value' => $this->encodeSortRules($query->getOrder())
                )
            ));
        }

        $count = 0;
        $cookie = '';
        $entries = array();
        do {
            // Do not request the pagination control as a critical extension, as we want the
            // server to return results even if the paged search request cannot be satisfied
            ldap_control_paged_result($this->ds, $pageSize, false, $cookie);

            $results = @ldap_search(
                $this->ds,
                $base,
                $queryString,
                array_values($fields),
                0, // Attributes and values
                $serverSorting && $limit ? $offset + $limit : 0
            );
            if ($results === false) {
                if (ldap_errno($this->ds) === self::LDAP_NO_SUCH_OBJECT) {
                    break;
                }

                throw new LdapException(
                    'LDAP query "%s" (base %s) failed. Error: %s',
                    $queryString,
                    $base,
                    ldap_error($this->ds)
                );
            } elseif (ldap_count_entries($this->ds, $results) === 0) {
                if (in_array(
                    ldap_errno($this->ds),
                    array(static::LDAP_SIZELIMIT_EXCEEDED, static::LDAP_ADMINLIMIT_EXCEEDED)
                )) {
                    Logger::warning(
                        'Unable to request more than %u results. Does the server allow paged search requests? (%s)',
                        $count,
                        ldap_error($this->ds)
                    );
                }

                break;
            }

            $entry = ldap_first_entry($this->ds, $results);
            do {
                $count += 1;
                if (! $serverSorting || $offset === 0 || $offset < $count) {
                    $entries[ldap_get_dn($this->ds, $entry)] = $this->cleanupAttributes(
                        ldap_get_attributes($this->ds, $entry), array_flip($fields)
                    );
                }
            } while (
                (! $serverSorting || $limit === 0 || $limit !== count($entries))
                && ($entry = ldap_next_entry($this->ds, $entry))
            );

            if (false === @ldap_control_paged_result_response($this->ds, $results, $cookie)) {
                // If the page size is greater than or equal to the sizeLimit value, the server should ignore the
                // control as the request can be satisfied in a single page: https://www.ietf.org/rfc/rfc2696.txt
                // This applies no matter whether paged search requests are permitted or not. You're done once you
                // got everything you were out for.
                if ($serverSorting && count($entries) !== $limit) {
                    // The server does not support pagination, but still returned a response by ignoring the
                    // pagedResultsControl. We output a warning to indicate that the pagination control was ignored.
                    Logger::warning(
                        'Unable to request paged LDAP results. Does the server allow paged search requests?'
                    );
                }
            }

            ldap_free_result($results);
        } while ($cookie && (! $serverSorting || $limit === 0 || count($entries) < $limit));

        if ($cookie) {
            // A sequence of paged search requests is abandoned by the client sending a search request containing a
            // pagedResultsControl with the size set to zero (0) and the cookie set to the last cookie returned by
            // the server: https://www.ietf.org/rfc/rfc2696.txt
            ldap_control_paged_result($this->ds, 0, false, $cookie);
            ldap_search($this->ds, $base, $queryString); // Returns no entries, due to the page size
        } else {
            // Reset the paged search request so that subsequent requests succeed
            ldap_control_paged_result($this->ds, 0);
        }

        if (! $serverSorting && $query->hasOrder()) {
            uasort($entries, array($query, 'compare'));
            if ($limit && $count > $limit) {
                $entries = array_splice($entries, $query->hasOffset() ? $query->getOffset() : 0, $limit);
            }
        }

        return $entries;
    }

    /**
     * Clean up the given attributes and return them as simple object
     *
     * Applies column aliases, aggregates multi-value attributes as array and sets null for each missing attribute.
     *
     * @param   array   $attributes
     * @param   array   $requestedFields
     *
     * @return  object
     */
    protected function cleanupAttributes($attributes, array $requestedFields)
    {
        // In case the result contains attributes with a differing case than the requested fields, it is
        // necessary to create another array to map attributes case insensitively to their requested counterparts.
        // This does also apply the virtual alias handling. (Since an LDAP server does not handle such)
        $loweredFieldMap = array();
        foreach ($requestedFields as $name => $alias) {
            $loweredFieldMap[strtolower($name)] = is_string($alias) ? $alias : $name;
        }

        $cleanedAttributes = array();
        for ($i = 0; $i < $attributes['count']; $i++) {
            $attribute_name = $attributes[$i];
            if ($attributes[$attribute_name]['count'] === 1) {
                $attribute_value = $attributes[$attribute_name][0];
            } else {
                $attribute_value = array();
                for ($j = 0; $j < $attributes[$attribute_name]['count']; $j++) {
                    $attribute_value[] = $attributes[$attribute_name][$j];
                }
            }

            $requestedAttributeName = isset($loweredFieldMap[strtolower($attribute_name)])
                ? $loweredFieldMap[strtolower($attribute_name)]
                : $attribute_name;
            $cleanedAttributes[$requestedAttributeName] = $attribute_value;
        }

        // The result may not contain all requested fields, so populate the cleaned
        // result with the missing fields and their value being set to null
        foreach ($requestedFields as $name => $alias) {
            if (! is_string($alias)) {
                $alias = $name;
            }

            if (! array_key_exists($alias, $cleanedAttributes)) {
                $cleanedAttributes[$alias] = null;
                Logger::debug('LDAP query result does not provide the requested field "%s"', $name);
            }
        }

        return (object) $cleanedAttributes;
    }

    /**
     * Encode the given array of sort rules as ASN.1 octet stream according to RFC 2891
     *
     * @param   array   $sortRules
     *
     * @return  string
     *
     * @todo    Produces an invalid stream, obviously
     */
    protected function encodeSortRules(array $sortRules)
    {
        if (count($sortRules) > 127) {
            throw new ProgrammingError(
                'Cannot encode more than 127 sort rules. Only length octets in short form are supported'
            );
        }

        $seq = '30' . str_pad(dechex(count($sortRules)), 2, '0', STR_PAD_LEFT);
        foreach ($sortRules as $rule) {
            $hexdAttribute = unpack('H*', $rule[0]);
            $seq .= '3002'
                . '04' . str_pad(dechex(strlen($rule[0])), 2, '0', STR_PAD_LEFT) . $hexdAttribute[1]
                . '0101' . ($rule[1] === Sortable::SORT_DESC ? 'ff' : '00');
        }

        return $seq;
    }

    /**
     *
     * Connect to the given ldap server and apply settings depending on the discovered capabilities
     *
     * @return resource        A positive LDAP link identifier
     * @throws LdapException   When the connection is not possible
     */
    protected function prepareNewConnection()
    {
        if ($this->encryption === static::STARTTLS || $this->encryption === static::LDAPS) {
            $this->prepareTlsEnvironment();
        }

        $hostname = $this->hostname;
        if ($this->encryption === static::LDAPS) {
            $hostname = 'ldaps://' . $hostname;
        }

        $ds = ldap_connect($hostname, $this->port);

        try {
            $this->capabilities = $this->discoverCapabilities($ds);
            $this->discoverySuccess = true;
        } catch (LdapException $e) {
            Logger::debug($e);
            Logger::warning('LADP discovery failed, assuming default LDAP capabilities.');
            $this->capabilities = new Capability(); // create empty default capabilities
            $this->discoverySuccess = false;
        }

        if ($this->encryption === static::STARTTLS) {
            $force_tls = false;
            if ($this->capabilities->hasStartTls()) {
                if (@ldap_start_tls($ds)) {
                    Logger::debug('LDAP STARTTLS succeeded');
                } else {
                    Logger::error('LDAP STARTTLS failed: %s', ldap_error($ds));
                    throw new LdapException('LDAP STARTTLS failed: %s', ldap_error($ds));
                }
            } elseif ($force_tls) {
                throw new LdapException('STARTTLS is required but not announced by %s', $this->hostname);
            } else {
                Logger::warning('LDAP STARTTLS enabled but not announced');
            }
        }

        // ldap_rename requires LDAPv3:
        if ($this->capabilities->hasLdapV3()) {
            if (! ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3)) {
                throw new LdapException('LDAPv3 is required');
            }
        } else {
            // TODO: remove this -> FORCING v3 for now
            ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
            Logger::warning('No LDAPv3 support detected');
        }

        // Not setting this results in "Operations error" on AD when using the whole domain as search base
        ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);
        // ldap_set_option($ds, LDAP_OPT_DEREF, LDAP_DEREF_NEVER);
        return $ds;
    }

    /**
     * Set up how to handle StartTLS connections
     *
     * @throws  LdapException   In case the LDAPRC environment variable cannot be set
     */
    protected function prepareTlsEnvironment()
    {
        // TODO: allow variable known CA location (system VS Icinga)
        if (Platform::isWindows()) {
            putenv('LDAPTLS_REQCERT=never');
        } else {
            if ($this->validateCertificate) {
                $ldap_conf = $this->getConfigDir('ldap_ca.conf');
            } else {
                $ldap_conf = $this->getConfigDir('ldap_nocert.conf');
            }

            putenv('LDAPRC=' . $ldap_conf); // TODO: Does not have any effect
            if (getenv('LDAPRC') !== $ldap_conf) {
                throw new LdapException('putenv failed');
            }
        }
    }

    /**
     *
     * Discover the capabilities of the given ldap-server
     *
     * @param  resource     $ds     The link identifier of the current ldap connection
     *
     * @return Capability           The capabilities
     * @throws LdapException        When the capability query fails
     */
    protected function discoverCapabilities($ds)
    {
        $fields = array(
            'defaultNamingContext',
            'namingContexts',
            'vendorName',
            'vendorVersion',
            'supportedSaslMechanisms',
            'dnsHostName',
            'schemaNamingContext',
            'supportedLDAPVersion', // => array(3, 2)
            'supportedCapabilities',
            'supportedControl',
            'supportedExtension',
            '+'
        );

        $result = @ldap_read($ds, '', (string) $this->select()->from('*', $fields), $fields);
        if (! $result) {
            throw new LdapException(
                'Capability query failed (%s:%d): %s. Check if hostname and port of the'
                . ' ldap resource are correct and if anonymous access is permitted.',
                $this->hostname,
                $this->port,
                ldap_error($ds)
            );
        }

        $entry = ldap_first_entry($ds, $result);
        if ($entry === false) {
            throw new LdapException(
                'Capabilities not available (%s:%d): %s. Discovery of root DSE probably not permitted.',
                $this->hostname,
                $this->port,
                ldap_error($ds)
            );
        }

        return new Capability($this->cleanupAttributes(ldap_get_attributes($ds, $entry), array_flip($fields)));
    }

    /**
     * Create an LDAP entry
     *
     * @param   string  $dn             The distinguished name to use
     * @param   array   $attributes     The entry's attributes
     *
     * @return  bool                    Whether the operation was successful
     */
    public function addEntry($dn, array $attributes)
    {
        return ldap_add($this->ds, $dn, $attributes);
    }

    /**
     * Modify an LDAP entry
     *
     * @param   string  $dn             The distinguished name to use
     * @param   array   $attributes     The attributes to update the entry with
     *
     * @return  bool                    Whether the operation was successful
     */
    public function modifyEntry($dn, array $attributes)
    {
        return ldap_modify($this->ds, $dn, $attributes);
    }

    /**
     * Change the distinguished name of an LDAP entry
     *
     * @param   string  $dn             The entry's current distinguished name
     * @param   string  $newRdn         The new relative distinguished name
     * @param   string  $newParentDn    The new parent or superior entry's distinguished name
     *
     * @return  resource                The resulting search result identifier
     *
     * @throws  LdapException           In case an error occured
     */
    public function moveEntry($dn, $newRdn, $newParentDn)
    {
        $result = ldap_rename($this->ds, $dn, $newRdn, $newParentDn, false);
        if ($result === false) {
            throw new LdapException('Could not move entry "%s" to "%s": %s', $dn, $newRdn, ldap_error($this->ds));
        }

        return $result;
    }

    /**
     * Return the LDAP specific configuration directory with the given relative path being appended
     *
     * @param   string  $sub
     *
     * @return  string
     */
    protected function getConfigDir($sub = null)
    {
        $dir = Config::$configDir . '/ldap';
        if ($sub !== null) {
            $dir .= '/' . $sub;
        }

        return $dir;
    }

    /**
     * Reset the environment variables set by self::prepareTlsEnvironment()
     */
    public function __destruct()
    {
        putenv('LDAPRC');
    }
}
