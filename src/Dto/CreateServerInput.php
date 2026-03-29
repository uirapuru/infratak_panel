<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateServerInput
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 32)]
    #[Assert\Regex(pattern: '/^[a-z0-9-]+$/', message: 'Name can contain only lowercase letters, digits and dashes.')]
    public string $name;
}
