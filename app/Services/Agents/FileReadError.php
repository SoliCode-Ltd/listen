<?php

namespace App\Services\Agents;

use RuntimeException;

/** Why a file could not be read, as one of FileReader::REASONS. */
class FileReadError extends RuntimeException {}
