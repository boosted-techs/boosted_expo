<?php

return [
    // These are supplied only after an immutable, approved deployment archive
    // is prepared. Both are required; a Git branch label is not a release.
    'artifact_sha256'=>env('EXPO_RELEASE_ARTIFACT_SHA256'),
    'artifact_path'=>env('EXPO_RELEASE_ARTIFACT_PATH'),
    'assets_sha256'=>env('EXPO_RELEASE_ASSETS_SHA256'),
];
