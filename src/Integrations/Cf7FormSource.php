<?php
/**
 * FormSource adapter for a Contact Form 7 submission. A thin data holder —
 * ContactForm7 reads WPCF7_Submission and the container post, then hands the
 * values here so this stays trivially testable.
 *
 * @package Mbuzz\WP\Integrations
 */

declare(strict_types=1);

namespace Mbuzz\WP\Integrations;

use Mbuzz\WP\Tracking\Source;

final class Cf7FormSource implements FormSource
{
    /**
     * @param object               $contactForm duck-typed: id(), title()
     * @param array<string, mixed> $posted      submission values keyed by field name
     * @param array<string, mixed> $page        {id, title, url} or []
     */
    public function __construct(
        private object $contactForm,
        private array $posted,
        private array $page,
    ) {
    }

    public function source(): string
    {
        return Source::CF7;
    }

    public function formId()
    {
        return (int) $this->contactForm->id();
    }

    public function formTitle(): string
    {
        return (string) $this->contactForm->title();
    }

    public function postedData(): array
    {
        return $this->posted;
    }

    public function page(): array
    {
        return $this->page;
    }
}
