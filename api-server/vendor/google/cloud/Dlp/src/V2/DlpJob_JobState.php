<?php
# Generated by the protocol buffer compiler.  DO NOT EDIT!
# source: google/privacy/dlp/v2/dlp.proto

namespace Google\Cloud\Dlp\V2;

/**
 * Protobuf enum <code>Google\Privacy\Dlp\V2\DlpJob\JobState</code>
 */
class DlpJob_JobState
{
    /**
     * Generated from protobuf enum <code>JOB_STATE_UNSPECIFIED = 0;</code>
     */
    const JOB_STATE_UNSPECIFIED = 0;
    /**
     * The job has not yet started.
     *
     * Generated from protobuf enum <code>PENDING = 1;</code>
     */
    const PENDING = 1;
    /**
     * The job is currently running.
     *
     * Generated from protobuf enum <code>RUNNING = 2;</code>
     */
    const RUNNING = 2;
    /**
     * The job is no longer running.
     *
     * Generated from protobuf enum <code>DONE = 3;</code>
     */
    const DONE = 3;
    /**
     * The job was canceled before it could complete.
     *
     * Generated from protobuf enum <code>CANCELED = 4;</code>
     */
    const CANCELED = 4;
    /**
     * The job had an error and did not complete.
     *
     * Generated from protobuf enum <code>FAILED = 5;</code>
     */
    const FAILED = 5;
}

