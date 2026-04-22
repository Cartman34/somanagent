<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Skill;

/**
 * Input DTO for updating skill content.
 */
final class UpdateSkillContentDto
{
    /**
     * @param string $content Updated skill content/instructions
     */
    public function __construct(
        public readonly string $content,
    ) {}

    /**
     * @throws \InvalidArgumentException with a short domain code on validation failure
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['content'])) {
            throw new \InvalidArgumentException('content_required');
        }

        return new self(
            content: (string) $data['content'],
        );
    }
}
