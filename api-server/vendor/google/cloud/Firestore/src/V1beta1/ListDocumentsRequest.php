<?php
# Generated by the protocol buffer compiler.  DO NOT EDIT!
# source: google/firestore/v1beta1/firestore.proto

namespace Google\Cloud\Firestore\V1beta1;

use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\RepeatedField;
use Google\Protobuf\Internal\GPBUtil;

/**
 * The request for [Firestore.ListDocuments][google.firestore.v1beta1.Firestore.ListDocuments].
 *
 * Generated from protobuf message <code>google.firestore.v1beta1.ListDocumentsRequest</code>
 */
class ListDocumentsRequest extends \Google\Protobuf\Internal\Message
{
    /**
     * The parent resource name. In the format:
     * `projects/{project_id}/databases/{database_id}/documents` or
     * `projects/{project_id}/databases/{database_id}/documents/{document_path}`.
     * For example:
     * `projects/my-project/databases/my-database/documents` or
     * `projects/my-project/databases/my-database/documents/chatrooms/my-chatroom`
     *
     * Generated from protobuf field <code>string parent = 1;</code>
     */
    private $parent = '';
    /**
     * The collection ID, relative to `parent`, to list. For example: `chatrooms`
     * or `messages`.
     *
     * Generated from protobuf field <code>string collection_id = 2;</code>
     */
    private $collection_id = '';
    /**
     * The maximum number of documents to return.
     *
     * Generated from protobuf field <code>int32 page_size = 3;</code>
     */
    private $page_size = 0;
    /**
     * The `next_page_token` value returned from a previous List request, if any.
     *
     * Generated from protobuf field <code>string page_token = 4;</code>
     */
    private $page_token = '';
    /**
     * The order to sort results by. For example: `priority desc, name`.
     *
     * Generated from protobuf field <code>string order_by = 6;</code>
     */
    private $order_by = '';
    /**
     * The fields to return. If not set, returns all fields.
     * If a document has a field that is not present in this mask, that field
     * will not be returned in the response.
     *
     * Generated from protobuf field <code>.google.firestore.v1beta1.DocumentMask mask = 7;</code>
     */
    private $mask = null;
    /**
     * If the list should show missing documents. A missing document is a
     * document that does not exist but has sub-documents. These documents will
     * be returned with a key but will not have fields, [Document.create_time][google.firestore.v1beta1.Document.create_time],
     * or [Document.update_time][google.firestore.v1beta1.Document.update_time] set.
     * Requests with `show_missing` may not specify `where` or
     * `order_by`.
     *
     * Generated from protobuf field <code>bool show_missing = 12;</code>
     */
    private $show_missing = false;
    protected $consistency_selector;

    public function __construct() {
        \GPBMetadata\Google\Firestore\V1Beta1\Firestore::initOnce();
        parent::__construct();
    }

    /**
     * The parent resource name. In the format:
     * `projects/{project_id}/databases/{database_id}/documents` or
     * `projects/{project_id}/databases/{database_id}/documents/{document_path}`.
     * For example:
     * `projects/my-project/databases/my-database/documents` or
     * `projects/my-project/databases/my-database/documents/chatrooms/my-chatroom`
     *
     * Generated from protobuf field <code>string parent = 1;</code>
     * @return string
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * The parent resource name. In the format:
     * `projects/{project_id}/databases/{database_id}/documents` or
     * `projects/{project_id}/databases/{database_id}/documents/{document_path}`.
     * For example:
     * `projects/my-project/databases/my-database/documents` or
     * `projects/my-project/databases/my-database/documents/chatrooms/my-chatroom`
     *
     * Generated from protobuf field <code>string parent = 1;</code>
     * @param string $var
     * @return $this
     */
    public function setParent($var)
    {
        GPBUtil::checkString($var, True);
        $this->parent = $var;

        return $this;
    }

    /**
     * The collection ID, relative to `parent`, to list. For example: `chatrooms`
     * or `messages`.
     *
     * Generated from protobuf field <code>string collection_id = 2;</code>
     * @return string
     */
    public function getCollectionId()
    {
        return $this->collection_id;
    }

    /**
     * The collection ID, relative to `parent`, to list. For example: `chatrooms`
     * or `messages`.
     *
     * Generated from protobuf field <code>string collection_id = 2;</code>
     * @param string $var
     * @return $this
     */
    public function setCollectionId($var)
    {
        GPBUtil::checkString($var, True);
        $this->collection_id = $var;

        return $this;
    }

    /**
     * The maximum number of documents to return.
     *
     * Generated from protobuf field <code>int32 page_size = 3;</code>
     * @return int
     */
    public function getPageSize()
    {
        return $this->page_size;
    }

    /**
     * The maximum number of documents to return.
     *
     * Generated from protobuf field <code>int32 page_size = 3;</code>
     * @param int $var
     * @return $this
     */
    public function setPageSize($var)
    {
        GPBUtil::checkInt32($var);
        $this->page_size = $var;

        return $this;
    }

    /**
     * The `next_page_token` value returned from a previous List request, if any.
     *
     * Generated from protobuf field <code>string page_token = 4;</code>
     * @return string
     */
    public function getPageToken()
    {
        return $this->page_token;
    }

    /**
     * The `next_page_token` value returned from a previous List request, if any.
     *
     * Generated from protobuf field <code>string page_token = 4;</code>
     * @param string $var
     * @return $this
     */
    public function setPageToken($var)
    {
        GPBUtil::checkString($var, True);
        $this->page_token = $var;

        return $this;
    }

    /**
     * The order to sort results by. For example: `priority desc, name`.
     *
     * Generated from protobuf field <code>string order_by = 6;</code>
     * @return string
     */
    public function getOrderBy()
    {
        return $this->order_by;
    }

    /**
     * The order to sort results by. For example: `priority desc, name`.
     *
     * Generated from protobuf field <code>string order_by = 6;</code>
     * @param string $var
     * @return $this
     */
    public function setOrderBy($var)
    {
        GPBUtil::checkString($var, True);
        $this->order_by = $var;

        return $this;
    }

    /**
     * The fields to return. If not set, returns all fields.
     * If a document has a field that is not present in this mask, that field
     * will not be returned in the response.
     *
     * Generated from protobuf field <code>.google.firestore.v1beta1.DocumentMask mask = 7;</code>
     * @return \Google\Cloud\Firestore\V1beta1\DocumentMask
     */
    public function getMask()
    {
        return $this->mask;
    }

    /**
     * The fields to return. If not set, returns all fields.
     * If a document has a field that is not present in this mask, that field
     * will not be returned in the response.
     *
     * Generated from protobuf field <code>.google.firestore.v1beta1.DocumentMask mask = 7;</code>
     * @param \Google\Cloud\Firestore\V1beta1\DocumentMask $var
     * @return $this
     */
    public function setMask($var)
    {
        GPBUtil::checkMessage($var, \Google\Cloud\Firestore\V1beta1\DocumentMask::class);
        $this->mask = $var;

        return $this;
    }

    /**
     * Reads documents in a transaction.
     *
     * Generated from protobuf field <code>bytes transaction = 8;</code>
     * @return string
     */
    public function getTransaction()
    {
        return $this->readOneof(8);
    }

    /**
     * Reads documents in a transaction.
     *
     * Generated from protobuf field <code>bytes transaction = 8;</code>
     * @param string $var
     * @return $this
     */
    public function setTransaction($var)
    {
        GPBUtil::checkString($var, False);
        $this->writeOneof(8, $var);

        return $this;
    }

    /**
     * Reads documents as they were at the given time.
     * This may not be older than 60 seconds.
     *
     * Generated from protobuf field <code>.google.protobuf.Timestamp read_time = 10;</code>
     * @return \Google\Protobuf\Timestamp
     */
    public function getReadTime()
    {
        return $this->readOneof(10);
    }

    /**
     * Reads documents as they were at the given time.
     * This may not be older than 60 seconds.
     *
     * Generated from protobuf field <code>.google.protobuf.Timestamp read_time = 10;</code>
     * @param \Google\Protobuf\Timestamp $var
     * @return $this
     */
    public function setReadTime($var)
    {
        GPBUtil::checkMessage($var, \Google\Protobuf\Timestamp::class);
        $this->writeOneof(10, $var);

        return $this;
    }

    /**
     * If the list should show missing documents. A missing document is a
     * document that does not exist but has sub-documents. These documents will
     * be returned with a key but will not have fields, [Document.create_time][google.firestore.v1beta1.Document.create_time],
     * or [Document.update_time][google.firestore.v1beta1.Document.update_time] set.
     * Requests with `show_missing` may not specify `where` or
     * `order_by`.
     *
     * Generated from protobuf field <code>bool show_missing = 12;</code>
     * @return bool
     */
    public function getShowMissing()
    {
        return $this->show_missing;
    }

    /**
     * If the list should show missing documents. A missing document is a
     * document that does not exist but has sub-documents. These documents will
     * be returned with a key but will not have fields, [Document.create_time][google.firestore.v1beta1.Document.create_time],
     * or [Document.update_time][google.firestore.v1beta1.Document.update_time] set.
     * Requests with `show_missing` may not specify `where` or
     * `order_by`.
     *
     * Generated from protobuf field <code>bool show_missing = 12;</code>
     * @param bool $var
     * @return $this
     */
    public function setShowMissing($var)
    {
        GPBUtil::checkBool($var);
        $this->show_missing = $var;

        return $this;
    }

    /**
     * @return string
     */
    public function getConsistencySelector()
    {
        return $this->whichOneof("consistency_selector");
    }

}

