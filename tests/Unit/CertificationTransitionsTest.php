<?php

namespace Tests\Unit;

use App\Models\CertificationRequest;
use PHPUnit\Framework\TestCase;

// The request status machine (CertificationRequest::TRANSITIONS). No database needed.
class CertificationTransitionsTest extends TestCase
{
    private function requestIn(string $status): CertificationRequest
    {
        $r = new CertificationRequest();
        $r->status = $status;

        return $r;
    }

    public function test_a_new_request_can_only_be_assigned_or_cancelled()
    {
        $r = $this->requestIn('submitted');

        $this->assertTrue($r->canMoveTo('assigned'));
        $this->assertTrue($r->canMoveTo('cancelled'));
        $this->assertFalse($r->canMoveTo('approved'));
        $this->assertFalse($r->canMoveTo('in_review'));
    }

    public function test_decided_requests_are_final()
    {
        foreach (['approved', 'rejected', 'cancelled'] as $final) {
            foreach (array_keys(CertificationRequest::TRANSITIONS) as $to) {
                $this->assertFalse($this->requestIn($final)->canMoveTo($to), "$final -> $to should not be allowed");
            }
        }
    }

    public function test_needs_info_goes_back_to_the_officer_or_is_cancelled()
    {
        $r = $this->requestIn('needs_info');

        $this->assertTrue($r->canMoveTo('assigned'));
        $this->assertTrue($r->canMoveTo('cancelled'));
        $this->assertFalse($r->canMoveTo('approved'));
    }

    public function test_a_request_under_review_cannot_be_cancelled_by_the_farmer()
    {
        $this->assertFalse($this->requestIn('in_review')->canMoveTo('cancelled'));
    }

    public function test_open_statuses_are_the_undecided_ones()
    {
        foreach (['submitted', 'assigned', 'in_review', 'needs_info'] as $s) {
            $this->assertTrue($this->requestIn($s)->isOpen());
        }
        foreach (['approved', 'rejected', 'cancelled'] as $s) {
            $this->assertFalse($this->requestIn($s)->isOpen());
        }
    }

    public function test_every_target_status_is_a_known_status()
    {
        $known = array_keys(CertificationRequest::TRANSITIONS);
        foreach (CertificationRequest::TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                $this->assertContains($to, $known, "$from -> $to points to an unknown status");
            }
        }
    }
}
