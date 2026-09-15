<?php
/**
 * Custom Image Model Metadata Directory
 *
 * @package DuetGAIConnector\Metadata
 */

namespace WordPress\DuetGAIConnector\Metadata;

use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\DuetGAIConnector\Settings\Settings;

/**
 * Custom Model Metadata Directory for Image Generation
 */
class CustomImageModelMetadataDirectory implements ModelMetadataDirectoryInterface
{
    public function listModelMetadata(): array
    {
        $modelId = $this->getConfiguredModelId();
        return [$this->getModelMetadata($modelId)];
    }

    public function hasModelMetadata(string $modelId): bool
    {
        return true;
    }

    public function getModelMetadata(string $modelId): ModelMetadata
    {
        $metadata = new ModelMetadata(
            $modelId,
            $modelId,
            [CapabilityEnum::imageGeneration()],
            [
                // Accept either text-only prompts (generations) or text+image
                // prompts (refinements). The official AI plugin's Generate_Image
                // ability calls `is_supported_for_image_generation()`, which
                // walks these variants and only returns true if one of them
                // matches the messages in the prompt builder. Without image
                // here, every reference-bearing request would surface as
                // "Image refinement failed. Please ensure you have a
                // connected provider that supports image refinement..."
                // regardless of whether the configured model can actually
                // edit. (Per the upstream prompt builder, providers that
                // truly can't refine will still return a model-level error
                // when the API rejects the image input.)
                new SupportedOption(
                    OptionEnum::inputModalities(),
                    [
                        [ModalityEnum::text()],
                        [ModalityEnum::text(), ModalityEnum::image()],
                    ]
                ),
                new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
                new SupportedOption(OptionEnum::outputMediaOrientation(), [
                    MediaOrientationEnum::square(),
                    MediaOrientationEnum::landscape(),
                    MediaOrientationEnum::portrait(),
                ]),
                new SupportedOption(OptionEnum::outputMediaAspectRatio(), ['1:1', '16:9', '9:16', '4:3', '3:4']),
                new SupportedOption(OptionEnum::outputFileType()),
                new SupportedOption(OptionEnum::candidateCount()),
            ]
        );
        return $metadata;
    }

    private function getConfiguredModelId(): string
    {
        return Settings::getImageModel();
    }
}
