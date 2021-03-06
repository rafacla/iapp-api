<?php
# Generated by the protocol buffer compiler.  DO NOT EDIT!
# source: google/privacy/dlp/v2/dlp.proto

namespace Google\Cloud\Dlp\V2;

use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\RepeatedField;
use Google\Protobuf\Internal\GPBUtil;

/**
 * A tuple of values for the quasi-identifier columns.
 *
 * Generated from protobuf message <code>google.privacy.dlp.v2.AnalyzeDataSourceRiskDetails.DeltaPresenceEstimationResult.DeltaPresenceEstimationQuasiIdValues</code>
 */
class AnalyzeDataSourceRiskDetails_DeltaPresenceEstimationResult_DeltaPresenceEstimationQuasiIdValues extends \Google\Protobuf\Internal\Message
{
    /**
     * The quasi-identifier values.
     *
     * Generated from protobuf field <code>repeated .google.privacy.dlp.v2.Value quasi_ids_values = 1;</code>
     */
    private $quasi_ids_values;
    /**
     * The estimated probability that a given individual sharing these
     * quasi-identifier values is in the dataset. This value, typically called
     * δ, is the ratio between the number of records in the dataset with these
     * quasi-identifier values, and the total number of individuals (inside
     * *and* outside the dataset) with these quasi-identifier values.
     * For example, if there are 15 individuals in the dataset who share the
     * same quasi-identifier values, and an estimated 100 people in the entire
     * population with these values, then δ is 0.15.
     *
     * Generated from protobuf field <code>double estimated_probability = 2;</code>
     */
    private $estimated_probability = 0.0;

    public function __construct() {
        \GPBMetadata\Google\Privacy\Dlp\V2\Dlp::initOnce();
        parent::__construct();
    }

    /**
     * The quasi-identifier values.
     *
     * Generated from protobuf field <code>repeated .google.privacy.dlp.v2.Value quasi_ids_values = 1;</code>
     * @return \Google\Protobuf\Internal\RepeatedField
     */
    public function getQuasiIdsValues()
    {
        return $this->quasi_ids_values;
    }

    /**
     * The quasi-identifier values.
     *
     * Generated from protobuf field <code>repeated .google.privacy.dlp.v2.Value quasi_ids_values = 1;</code>
     * @param \Google\Cloud\Dlp\V2\Value[]|\Google\Protobuf\Internal\RepeatedField $var
     * @return $this
     */
    public function setQuasiIdsValues($var)
    {
        $arr = GPBUtil::checkRepeatedField($var, \Google\Protobuf\Internal\GPBType::MESSAGE, \Google\Cloud\Dlp\V2\Value::class);
        $this->quasi_ids_values = $arr;

        return $this;
    }

    /**
     * The estimated probability that a given individual sharing these
     * quasi-identifier values is in the dataset. This value, typically called
     * δ, is the ratio between the number of records in the dataset with these
     * quasi-identifier values, and the total number of individuals (inside
     * *and* outside the dataset) with these quasi-identifier values.
     * For example, if there are 15 individuals in the dataset who share the
     * same quasi-identifier values, and an estimated 100 people in the entire
     * population with these values, then δ is 0.15.
     *
     * Generated from protobuf field <code>double estimated_probability = 2;</code>
     * @return float
     */
    public function getEstimatedProbability()
    {
        return $this->estimated_probability;
    }

    /**
     * The estimated probability that a given individual sharing these
     * quasi-identifier values is in the dataset. This value, typically called
     * δ, is the ratio between the number of records in the dataset with these
     * quasi-identifier values, and the total number of individuals (inside
     * *and* outside the dataset) with these quasi-identifier values.
     * For example, if there are 15 individuals in the dataset who share the
     * same quasi-identifier values, and an estimated 100 people in the entire
     * population with these values, then δ is 0.15.
     *
     * Generated from protobuf field <code>double estimated_probability = 2;</code>
     * @param float $var
     * @return $this
     */
    public function setEstimatedProbability($var)
    {
        GPBUtil::checkDouble($var);
        $this->estimated_probability = $var;

        return $this;
    }

}

