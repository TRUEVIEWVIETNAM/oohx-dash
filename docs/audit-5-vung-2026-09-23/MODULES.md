# Module map

Complete source/symbol/hash inventory: [source-inventory.json](evidence/source-inventory.json). This index is derived from files, not menus.

## Controllers

| File | Symbols |
|---|---|
| [app/Http/Controllers/Api/V1/AuthController.php](../../app/Http/Controllers/Api/V1/AuthController.php) | `token` |
| [app/Http/Controllers/Api/V1/InventoryController.php](../../app/Http/Controllers/Api/V1/InventoryController.php) | `index`, `map`, `show`, `stats`, `venueTypes`, `networks`, `locations`, `owners`, `ownerDetail`, `resolveRelations`, `buildScreenQuery`, `expandCitySlugs`, `resolveArrayParam`, `applyScreenTypeFilter`, `applyOrientationFilter`, `applySort` |
| [app/Http/Controllers/Api/V1/OohxEstimateController.php](../../app/Http/Controllers/Api/V1/OohxEstimateController.php) | `__construct`, `topByCity`, `show` |
| [app/Http/Controllers/Api/V1/OwnerController.php](../../app/Http/Controllers/Api/V1/OwnerController.php) | `index`, `store`, `show`, `update`, `destroy`, `switchContext`, `stats` |
| [app/Http/Controllers/Api/V1/PlayerController.php](../../app/Http/Controllers/Api/V1/PlayerController.php) | `heartbeat`, `impression` |
| [app/Http/Controllers/Api/V1/ScreenController.php](../../app/Http/Controllers/Api/V1/ScreenController.php) | `index`, `store`, `show`, `update`, `destroy`, `multipliers`, `updateMultipliers`, `toggleProgrammatic`, `saveSpec`, `saveInventory` |
| [app/Http/Controllers/Api/V1/SiteController.php](../../app/Http/Controllers/Api/V1/SiteController.php) | `index`, `store`, `show`, `update`, `destroy` |
| [app/Http/Controllers/Api/V1/WebhookController.php](../../app/Http/Controllers/Api/V1/WebhookController.php) | `register` |
| [app/Http/Controllers/Buyer/BookingController.php](../../app/Http/Controllers/Buyer/BookingController.php) | `__construct`, `create`, `store`, `creative`, `uploadCreative`, `review`, `submit`, `authorizeCampaign` |
| [app/Http/Controllers/Buyer/BuyerAuthController.php](../../app/Http/Controllers/Buyer/BuyerAuthController.php) | `__construct`, `showLogin`, `login`, `showRegister`, `register`, `logout` |
| [app/Http/Controllers/Buyer/BuyerCampaignController.php](../../app/Http/Controllers/Buyer/BuyerCampaignController.php) | `__construct`, `index`, `show` |
| [app/Http/Controllers/Buyer/BuyerDashboardController.php](../../app/Http/Controllers/Buyer/BuyerDashboardController.php) | `index` |
| [app/Http/Controllers/Buyer/BuyerReportController.php](../../app/Http/Controllers/Buyer/BuyerReportController.php) | `__construct`, `show` |
| [app/Http/Controllers/Buyer/BuyerSettingsController.php](../../app/Http/Controllers/Buyer/BuyerSettingsController.php) | `index`, `updateProfile`, `updatePassword`, `updateOrganization` |
| [app/Http/Controllers/Buyer/CartController.php](../../app/Http/Controllers/Buyer/CartController.php) | `__construct`, `index`, `add`, `update`, `remove`, `count` |
| [app/Http/Controllers/Buyer/OwnerReviewController.php](../../app/Http/Controllers/Buyer/OwnerReviewController.php) | `__construct`, `store` |
| [app/Http/Controllers/Buyer/PaymentController.php](../../app/Http/Controllers/Buyer/PaymentController.php) | `__construct`, `show`, `process`, `success`, `authorize` |
| [app/Http/Controllers/Controller.php](../../app/Http/Controllers/Controller.php) |  |
| [app/Http/Controllers/FrontpageController.php](../../app/Http/Controllers/FrontpageController.php) | `__construct`, `index`, `listing`, `detail`, `resolveNearbyPois`, `map`, `booking`, `agency`, `owners`, `ownerDetail` |
| [app/Http/Controllers/InvitationController.php](../../app/Http/Controllers/InvitationController.php) | `__construct`, `show`, `store` |
| [app/Http/Controllers/PolicyController.php](../../app/Http/Controllers/PolicyController.php) | `show` |
| [app/Http/Controllers/ProductController.php](../../app/Http/Controllers/ProductController.php) | `__construct`, `index`, `show` |
| [app/Http/Controllers/PublicReflectionController.php](../../app/Http/Controllers/PublicReflectionController.php) | `__construct`, `create`, `store`, `index` |
| [app/Http/Controllers/SitemapController.php](../../app/Http/Controllers/SitemapController.php) | `index` |

## Services

| File | Symbols |
|---|---|
| [app/Services/AvailabilityService.php](../../app/Services/AvailabilityService.php) | `getBookedSOV`, `getRemainingSOV`, `isAvailable`, `validateCampaign` |
| [app/Services/CampaignReportService.php](../../app/Services/CampaignReportService.php) | `getOverview`, `getDailyImpressions`, `getScreenBreakdown` |
| [app/Services/CampaignService.php](../../app/Services/CampaignService.php) | `createFromCart`, `submit`, `approveLines`, `rejectLines`, `approveAllForOwner`, `rejectAllForOwner`, `checkAllLinesResolved`, `notifyBuyer`, `generateCode` |
| [app/Services/CartService.php](../../app/Services/CartService.php) | `getOrCreateCart`, `addProduct`, `addItem`, `removeItem`, `updateItem`, `estimateCost`, `getItemCount` |
| [app/Services/FrontpageService.php](../../app/Services/FrontpageService.php) | `getHeroStats`, `getVenueTypesWithCounts`, `getCategoriesWithNetworks`, `getVnCategoryLabels`, `getVnCategorySlugs`, `getVnCategoryIcons`, `getTopCities`, `getLocationsByRegion`, `getFeaturedScreens`, `getFeaturedOwners`, `getOwnersPaginated`, `getAgenciesPaginated`, `getOwnerBySlug`, `getOwnerScreens`, `getOwnerFilterAggregates`, `getNetworksPaginated`, `getSitesPaginated`, `getScreensPaginated`, `getFilterAggregates`, `getScreenDetail`, `getSimilarScreens`, `getMapPins`, `getHomepageMapPins`, `resolveCityName`, `applyCityFilter`, `getHomepageMapCount`, `loadOwnerExtras`, `buildScreenQuery`, `expandCitySlugs`, `resolveArrayParam`, `applyScreenTypeFilter`, `applyOrientationFilter`, `applySort`, `cityToSlug` |
| [app/Services/GeocodingService.php](../../app/Services/GeocodingService.php) | `resolveLocation`, `buildCityHierarchy`, `rawGeocode`, `callNominatim`, `matchProvince`, `matchCommune`, `normalize`, `ascii` |
| [app/Services/Oohx/AnalyticsService.php](../../app/Services/Oohx/AnalyticsService.php) | `weeklyTrend`, `weekOverWeek`, `topCities`, `topUtilizedScreens`, `utilizationCounts`, `formulaVersionImpact`, `staleness`, `isAvailable`, `flushCache`, `safeCachedQuery` |
| [app/Services/Oohx/CollectorManager.php](../../app/Services/Oohx/CollectorManager.php) | `trigger`, `cancel`, `latestByCollectorAndCity`, `computeStaleness`, `cityOptionsForCollector`, `cityHasScreens`, `countsByStatus`, `overdueCount`, `collectorMeta`, `listCollectors`, `citiesFromScreens`, `resolveActor` |
| [app/Services/Oohx/ConfigManagerService.php](../../app/Services/Oohx/ConfigManagerService.php) | `updateCoefficient`, `publishVersion`, `activateVersion`, `updateSeasonalityFactor`, `seasonalityHeatmapData`, `diffVersions`, `buildSnapshot`, `resolveActor`, `activeVersion`, `listCoefficients` |
| [app/Services/Oohx/HealthDigestService.php](../../app/Services/Oohx/HealthDigestService.php) | `latest`, `forget`, `latestPath`, `readFile`, `computeAgeMinutes`, `overallBadge`, `checkColor`, `checkLabel`, `checkIcon`, `formatCheckValue` |
| [app/Services/Oohx/JobOrchestrator.php](../../app/Services/Oohx/JobOrchestrator.php) | `enqueueScreen`, `enqueueCity`, `enqueueBulk`, `enqueueBulkAction`, `enqueuePreview`, `hasRecentPreview`, `enqueueCampaign`, `retry`, `cancel`, `getProgress`, `countsByStatus`, `tagActor`, `resolveActor`, `audit` |
| [app/Services/Oohx/PreviewResultFormatter.php](../../app/Services/Oohx/PreviewResultFormatter.php) | `warningTier`, `overallWarning`, `pct`, `num`, `metricLabel`, `barWidth` |
| [app/Services/OohxDataEngine.php](../../app/Services/OohxDataEngine.php) | `connection`, `getEstimateByExternalId`, `getEstimatesByExternalIds`, `topScreensByImpressions` |
| [app/Services/OwnerReviewService.php](../../app/Services/OwnerReviewService.php) | `reviewableOwners`, `campaignIsReviewable`, `submit`, `summaryFor`, `publishedFor` |
| [app/Services/PaymentService.php](../../app/Services/PaymentService.php) | `createPayment`, `confirmBankTransfer`, `markFailed`, `checkAndActivate`, `getSummary`, `breakdownByOwner`, `generateTransactionRef`, `generateInvoiceNumber` |
| [app/Services/PoiContextEnricher.php](../../app/Services/PoiContextEnricher.php) | `enrichScreen`, `enrichByCoords`, `fetchPoisOnly`, `queryOverpass`, `categoryMap`, `aggregate`, `haversine`, `buildPrompt`, `callClaude`, `extractJson`, `applyToScreen` |
| [app/Services/PolicyConsentService.php](../../app/Services/PolicyConsentService.php) | `record`, `currentVersion` |
| [app/Services/ProductService.php](../../app/Services/ProductService.php) | `getProductsPaginated`, `getProductBySlug`, `getSimilarProducts`, `getFeaturedProducts`, `getFilterAggregates` |
| [app/Services/PublicReflectionService.php](../../app/Services/PublicReflectionService.php) | `record`, `published`, `generateCode` |
| [app/Services/ScreenImport/ColumnMappingAiService.php](../../app/Services/ScreenImport/ColumnMappingAiService.php) | `propose`, `buildPrompt`, `parseMapping`, `extractJson` |
| [app/Services/ScreenImport/ErrorReportExporter.php](../../app/Services/ScreenImport/ErrorReportExporter.php) | `generate` |
| [app/Services/ScreenImport/FieldCatalog.php](../../app/Services/ScreenImport/FieldCatalog.php) | `all`, `get`, `isValid`, `groupedOptions`, `flatOptions`, `promptSchema`, `requiredKeys` |
| [app/Services/ScreenImport/RowTransformer.php](../../app/Services/ScreenImport/RowTransformer.php) | `transform`, `assignField`, `coerce`, `parseNumber`, `parseBool`, `parseTime`, `splitCompound` |
| [app/Services/ScreenImport/RowValidator.php](../../app/Services/ScreenImport/RowValidator.php) | `__construct`, `validate`, `resolveSiteId`, `resolveVnCategoryId`, `screenExternalIdExists` |
| [app/Services/ScreenImport/ScreenImportService.php](../../app/Services/ScreenImport/ScreenImportService.php) | `__construct`, `analyze`, `proposeMapping`, `refineMapping`, `saveUserMapping`, `dryRun`, `queueExecution`, `execute` |
| [app/Services/ScreenImport/ScreenWriter.php](../../app/Services/ScreenImport/ScreenWriter.php) | `__construct`, `write`, `createSite` |
| [app/Services/ScreenImport/SpreadsheetReader.php](../../app/Services/ScreenImport/SpreadsheetReader.php) | `__construct`, `analyze`, `iterate`, `isEmpty` |
| [app/Services/ScreenImport/TemplateGenerator.php](../../app/Services/ScreenImport/TemplateGenerator.php) | `generate` |
| [app/Services/SiteImportService.php](../../app/Services/SiteImportService.php) | `__construct`, `readPreview`, `import`, `parseSitesSheet`, `parseScreensSheet`, `parseNum`, `parseInt`, `parseTime` |
| [app/Services/TenantPermission.php](../../app/Services/TenantPermission.php) | `__construct`, `check`, `for`, `can`, `cannot`, `role`, `roleLabel`, `getOwnerUser` |
| [app/Services/UserInvitationService.php](../../app/Services/UserInvitationService.php) | `invite`, `accept`, `attachToTenant`, `guardRole`, `guardNotAlreadyMember`, `generateToken` |

## Models and external Data Engine models

| File | Symbols |
|---|---|
| [app/Models/ApiClient.php](../../app/Models/ApiClient.php) | `validateSecret`, `hasScope`, `revokeAllTokens`, `allowedScopes` |
| [app/Models/BookingLine.php](../../app/Models/BookingLine.php) | `campaign`, `product`, `screen`, `owner`, `approvedBy`, `creatives`, `getEffectiveCpmAttribute`, `getDurationDaysAttribute`, `scopeForOwner`, `scopePending`, `scopeActive` |
| [app/Models/BookingLineCreative.php](../../app/Models/BookingLineCreative.php) | `bookingLine`, `creative` |
| [app/Models/Campaign.php](../../app/Models/Campaign.php) | `organization`, `createdBy`, `bookingLines`, `creatives`, `payments`, `activities`, `getTotalEstimatedCostAttribute`, `getTotalActualCostAttribute`, `getTotalActualImpressionsAttribute`, `getDeliveryRateAttribute`, `getTotalPaidAttribute`, `scopeByStatus`, `scopeForOrganization`, `scopeForOwner`, `isDraft`, `isPending`, `isActive`, `isCompleted` |
| [app/Models/CampaignActivity.php](../../app/Models/CampaignActivity.php) | `campaign`, `user`, `log` |
| [app/Models/Cart.php](../../app/Models/Cart.php) | `user`, `organization`, `items`, `scopeActive`, `getTotalEstimatedCostAttribute`, `getTotalScreensAttribute` |
| [app/Models/CartItem.php](../../app/Models/CartItem.php) | `cart`, `screen`, `product` |
| [app/Models/Creative.php](../../app/Models/Creative.php) | `campaign`, `organization`, `reviewedBy`, `bookingLines`, `getFileUrlAttribute`, `getDimensionsAttribute` |
| [app/Models/ImpressionLog.php](../../app/Models/ImpressionLog.php) | `screen`, `owner` |
| [app/Models/Network.php](../../app/Models/Network.php) | `booted`, `owner`, `vnCategory`, `sites`, `screens`, `products`, `scopeActive` |
| [app/Models/Oohx/AnalyticsCampaignWeekly.php](../../app/Models/Oohx/AnalyticsCampaignWeekly.php) | `save`, `delete` |
| [app/Models/Oohx/AnalyticsCityPerformance.php](../../app/Models/Oohx/AnalyticsCityPerformance.php) | `save`, `delete` |
| [app/Models/Oohx/AnalyticsFormulaVersionImpact.php](../../app/Models/Oohx/AnalyticsFormulaVersionImpact.php) | `save`, `delete` |
| [app/Models/Oohx/AnalyticsScreenUtilization.php](../../app/Models/Oohx/AnalyticsScreenUtilization.php) | `save`, `delete` |
| [app/Models/Oohx/CampaignEstimate.php](../../app/Models/Oohx/CampaignEstimate.php) | `getScreenIdsAttribute`, `getConfidenceTierAttribute`, `getConfidenceColorAttribute`, `getFrequencyWarningAttribute`, `getFrequencyColorAttribute`, `getMissingScreensWarningAttribute`, `getScreenCountAttribute`, `getBudgetLabelAttribute` |
| [app/Models/Oohx/CollectorRun.php](../../app/Models/Oohx/CollectorRun.php) | `scopePending`, `scopeRunning`, `scopeDone`, `scopeFailed`, `scopeCancelled`, `scopeActive`, `scopeForCollector`, `getDurationSecondsAttribute`, `getIsCancellableAttribute`, `getIsActiveAttribute`, `getBytesFetchedHumanAttribute` |
| [app/Models/Oohx/Config/AuditLog.php](../../app/Models/Oohx/Config/AuditLog.php) |  |
| [app/Models/Oohx/Config/BaseCityTraffic.php](../../app/Models/Oohx/Config/BaseCityTraffic.php) |  |
| [app/Models/Oohx/Config/DeliveryDefault.php](../../app/Models/Oohx/Config/DeliveryDefault.php) | `rangeFor` |
| [app/Models/Oohx/Config/FormulaVersion.php](../../app/Models/Oohx/Config/FormulaVersion.php) |  |
| [app/Models/Oohx/Config/RoadClassMultiplier.php](../../app/Models/Oohx/Config/RoadClassMultiplier.php) |  |
| [app/Models/Oohx/Config/SeasonalityFactor.php](../../app/Models/Oohx/Config/SeasonalityFactor.php) | `getKey`, `getRouteKey`, `getRouteKeyName`, `resolveRouteBinding`, `scopeByKey`, `scopeForCity`, `scopeForMonth`, `getMonthLabelAttribute`, `getFactorColorAttribute` |
| [app/Models/Oohx/Config/ZoneFactor.php](../../app/Models/Oohx/Config/ZoneFactor.php) |  |
| [app/Models/Oohx/RecomputeJob.php](../../app/Models/Oohx/RecomputeJob.php) | `screen`, `scopePending`, `scopeProcessing`, `scopeFailed`, `scopeDone`, `scopeCancelled`, `scopeBulk`, `scopeActive`, `getDurationSecondsAttribute`, `getActionAttribute`, `getProgressDataAttribute`, `getProgressPercentAttribute`, `getTargetLabelAttribute`, `renderVenueFootfallLabel`, `getIsPreviewAttribute`, `getPreviewResultAttribute`, `getIsCampaignAttribute`, `getCampaignResultAttribute`, `getCampaignIdAttribute`, `getIsVenueFootfallAttribute`, `getVenueFootfallResultAttribute`, `getIsCancellableAttribute`, `getIsActiveAttribute` |
| [app/Models/Oohx/Screen.php](../../app/Models/Oohx/Screen.php) | `estimate` |
| [app/Models/Oohx/ScreenContextMetrics.php](../../app/Models/Oohx/ScreenContextMetrics.php) | `screen`, `getWeatherFactorColorAttribute`, `getSeasonalityFactorColorAttribute`, `getHasCompleteDataAttribute`, `getCompletenessTierAttribute`, `getCompletenessBadgeColorAttribute`, `getCompletenessBadgeLabelAttribute`, `getMissingDataReasonsAttribute`, `getVenueFootfallIsStaleAttribute`, `getVenueFootfallSourceColorAttribute` |
| [app/Models/Oohx/ScreenEstimate.php](../../app/Models/Oohx/ScreenEstimate.php) | `screen`, `contextMetrics` |
| [app/Models/Organization.php](../../app/Models/Organization.php) | `users`, `organizationUsers`, `campaigns`, `carts`, `creatives`, `payments`, `scopeActive` |
| [app/Models/OrganizationUser.php](../../app/Models/OrganizationUser.php) | `organization`, `user`, `can`, `isAdmin`, `assignableRolesFor` |
| [app/Models/Owner.php](../../app/Models/Owner.php) | `province`, `commune`, `sites`, `networks`, `screens`, `products`, `users`, `impressionLogs`, `scopeActive`, `gateActive`, `hasBankDetails`, `hasCompleteLegalProfile` |
| [app/Models/OwnerReview.php](../../app/Models/OwnerReview.php) | `campaign`, `owner`, `organization`, `user`, `scopePublished` |
| [app/Models/OwnerUser.php](../../app/Models/OwnerUser.php) | `owner`, `user`, `can`, `canAccessNetwork`, `getRoleLabelAttribute`, `roleOptions`, `assignableRolesFor`, `isOwner` |
| [app/Models/Payment.php](../../app/Models/Payment.php) | `campaign`, `organization`, `owner`, `isPending`, `isCompleted`, `isFailed` |
| [app/Models/PoiSnapshot.php](../../app/Models/PoiSnapshot.php) | `locationKey`, `scopeFreshFor`, `upsertSnapshot` |
| [app/Models/PolicyConsent.php](../../app/Models/PolicyConsent.php) | `user` |
| [app/Models/Product.php](../../app/Models/Product.php) | `owner`, `network`, `site`, `screens`, `primaryScreen`, `bookingLines`, `cartItems`, `getCoverUrlAttribute`, `getPhotoUrlsAttribute`, `getCategoryLabelAttribute`, `getPriceDisplayAttribute`, `getScreenCountAttribute`, `scopeActive`, `scopePubliclyVisible`, `scopeFeatured`, `scopeByCategory`, `scopeByType`, `scopeByCity`, `isSingle`, `isPackage`, `isCustom`, `allowsIndividual`, `allowsPackage`, `getIndividualPriceDisplayAttribute`, `getListingModeLabelAttribute` |
| [app/Models/ProductScreen.php](../../app/Models/ProductScreen.php) | `product`, `screen` |
| [app/Models/PublicReflection.php](../../app/Models/PublicReflection.php) | `handledBy`, `scopePublished`, `statusLabel` |
| [app/Models/SavedItem.php](../../app/Models/SavedItem.php) | `user`, `screen` |
| [app/Models/Screen.php](../../app/Models/Screen.php) | `booted`, `owner`, `products`, `site`, `network`, `getNetworkViaAttribute`, `getDisplayPhotoAttribute`, `spec`, `inventory`, `multipliers`, `externalIds`, `impressionLogs`, `scopeActive`, `scopePubliclyVisible`, `scopeOnline`, `scopeProgrammatic`, `scopeByVenueType`, `getCurrentMultiplier`, `isOperating`, `getIsOnlineAttribute`, `getOrientationAttribute`, `getDailyImpressionsAttribute`, `getHasInsightsAttribute`, `getTrafficEstimateAttribute` |
| [app/Models/ScreenExternalId.php](../../app/Models/ScreenExternalId.php) | `screen` |
| [app/Models/ScreenImport.php](../../app/Models/ScreenImport.php) | `owner`, `uploader`, `getIsTerminalAttribute`, `getIsActiveAttribute`, `getProgressPercentAttribute`, `getEffectiveMappingAttribute` |
| [app/Models/ScreenImpressionMultiplier.php](../../app/Models/ScreenImpressionMultiplier.php) | `screen`, `parseHivestackString` |
| [app/Models/ScreenInventory.php](../../app/Models/ScreenInventory.php) | `screen`, `network`, `vnCategory`, `computeFloorCpmUsd`, `getDailyImpressionsAttribute`, `allowsCpm`, `allowsIo`, `isBoth`, `getDisplayPriceAttribute`, `getDisplayPriceUnitAttribute`, `getEffectiveScreenCountAttribute` |
| [app/Models/ScreenSpec.php](../../app/Models/ScreenSpec.php) | `screen`, `getPhotoAttribute`, `getPhotoUrlsAttribute`, `getOrientationAttribute`, `getAspectRatioAttribute`, `gcd` |
| [app/Models/Site.php](../../app/Models/Site.php) | `booted`, `owner`, `network`, `province`, `commune`, `screens`, `scopeActive` |
| [app/Models/User.php](../../app/Models/User.php) | `casts`, `ownerUsers`, `owners`, `currentOwner`, `organizationUsers`, `organizations`, `currentOrganization`, `switchOrganization`, `isBuyer`, `switchOwner`, `getRoleInOwner`, `canAccessPanel` |
| [app/Models/UserInvitation.php](../../app/Models/UserInvitation.php) | `invitedBy`, `tenant`, `scopeValid`, `scopePending`, `isExpired`, `isAccepted`, `isValid` |
| [app/Models/VenueCategory.php](../../app/Models/VenueCategory.php) | `venueTypes`, `scopeActive`, `getStringValues` |
| [app/Models/VenueType.php](../../app/Models/VenueType.php) | `parent`, `vnCategory`, `children`, `scopeByCategory`, `scopeHivestackSupported`, `scopeActive`, `scopeRoots`, `scopeLeaves`, `scopeForSelect`, `groupedOptions`, `flatOptions`, `getIndentedNameAttribute` |
| [app/Models/VietnamCommune.php](../../app/Models/VietnamCommune.php) | `province`, `sites`, `optionsForProvince` |
| [app/Models/VietnamProvince.php](../../app/Models/VietnamProvince.php) | `getPhotoAttribute`, `region`, `communes`, `sites`, `getDisplayNameAttribute`, `toSelectOptions` |
| [app/Models/VietnamRegion.php](../../app/Models/VietnamRegion.php) | `provinces`, `toSelectOptions` |
| [app/Models/WebhookSubscription.php](../../app/Models/WebhookSubscription.php) | `apiClient`, `scopeActive`, `scopeForEvent` |

## Jobs and scheduler commands

| File | Symbols |
|---|---|
| [app/Jobs/ImportScreensJob.php](../../app/Jobs/ImportScreensJob.php) | `__construct`, `handle`, `failed` |
| [app/Jobs/SendWebhookJob.php](../../app/Jobs/SendWebhookJob.php) | `__construct`, `handle`, `backoff` |

## Scheduled / CLI commands

| File | Symbols |
|---|---|
| [app/Console/Commands/BackfillPoiSnapshots.php](../../app/Console/Commands/BackfillPoiSnapshots.php) | `handle` |
| [app/Console/Commands/BackfillSlugs.php](../../app/Console/Commands/BackfillSlugs.php) | `handle`, `backfillTable` |
| [app/Console/Commands/EnrichContextPoc.php](../../app/Console/Commands/EnrichContextPoc.php) | `handle` |
| [app/Console/Commands/EnrichScreens.php](../../app/Console/Commands/EnrichScreens.php) | `handle` |
| [app/Console/Commands/FetchVietnamAdminDivisions.php](../../app/Console/Commands/FetchVietnamAdminDivisions.php) | `handle`, `syncProvinces`, `fetchCommunes`, `apiGet`, `resolveType` |
| [app/Console/Commands/FixIoPricing.php](../../app/Console/Commands/FixIoPricing.php) | `handle` |
| [app/Console/Commands/GenerateImportTemplate.php](../../app/Console/Commands/GenerateImportTemplate.php) | `handle`, `buildSitesSheet`, `buildScreensSheet`, `buildGuideSheet`, `styleTitle`, `styleHeaderRow`, `getVenueOptions` |
| [app/Console/Commands/GeocodeSitesCommand.php](../../app/Console/Commands/GeocodeSitesCommand.php) | `handle` |
| [app/Console/Commands/ImportVietnamAdminDivisions.php](../../app/Console/Commands/ImportVietnamAdminDivisions.php) | `handle`, `importProvinces`, `importCommunes` |
| [app/Console/Commands/OohxExportScreens.php](../../app/Console/Commands/OohxExportScreens.php) | `handle`, `projectScreen`, `deriveIndoorOutdoor`, `deriveScreenSize`, `normalizeCity` |
| [app/Console/Commands/OohxFetchHealthDigest.php](../../app/Console/Commands/OohxFetchHealthDigest.php) | `handle`, `buildTargets`, `recordFetch` |
| [app/Console/Commands/OohxSyncToEngine.php](../../app/Console/Commands/OohxSyncToEngine.php) | `handle`, `parseIngestOutput`, `runCmd` |
| [app/Console/Commands/PruneExpiredInvitations.php](../../app/Console/Commands/PruneExpiredInvitations.php) | `handle` |
| [app/Console/Commands/PruneLivewireTmpUploads.php](../../app/Console/Commands/PruneLivewireTmpUploads.php) | `handle` |
| [app/Console/Commands/SeedInsightsDefaults.php](../../app/Console/Commands/SeedInsightsDefaults.php) | `templates`, `handle`, `hasAnyData` |

## Policies

| File | Symbols |
|---|---|
| [app/Policies/NetworkPolicy.php](../../app/Policies/NetworkPolicy.php) | `viewAny`, `view`, `create`, `update`, `delete`, `deleteAny`, `hasManagePermission` |
| [app/Policies/OrganizationUserPolicy.php](../../app/Policies/OrganizationUserPolicy.php) | `viewAny`, `view`, `create`, `update`, `delete`, `actorRole` |
| [app/Policies/OwnerPolicy.php](../../app/Policies/OwnerPolicy.php) | `viewAny`, `view`, `create`, `update`, `delete` |
| [app/Policies/OwnerUserPolicy.php](../../app/Policies/OwnerUserPolicy.php) | `viewAny`, `view`, `create`, `update`, `delete`, `actorRole` |
| [app/Policies/ScreenPolicy.php](../../app/Policies/ScreenPolicy.php) | `viewAny`, `view`, `create`, `update`, `delete`, `deleteAny`, `hasManagePermission` |
| [app/Policies/SitePolicy.php](../../app/Policies/SitePolicy.php) | `viewAny`, `view`, `create`, `update`, `delete`, `deleteAny`, `hasManagePermission` |

## Tests

| File | Symbols |
|---|---|
| [tests/Feature/Api/InventoryLocationsTest.php](../../tests/Feature/Api/InventoryLocationsTest.php) | `setUp`, `makeActiveScreen`, `getLocations`, `test_returns_correct_structure`, `test_returns_empty_arrays_when_no_screens`, `test_provinces_only_includes_cities_with_active_screens`, `test_province_count_is_correct`, `test_province_has_correct_region`, `test_region_count_is_rolled_up_from_provinces`, `test_region_not_returned_when_no_provinces_have_screens`, `test_districts_empty_when_no_district_code_set`, `test_districts_includes_district_with_code`, `test_district_count_aggregates_correctly`, `test_result_is_cached`, `test_unauthenticated_is_rejected` |
| [tests/Feature/Api/InventoryNetworksTest.php](../../tests/Feature/Api/InventoryNetworksTest.php) | `setUp`, `makeActiveScreenWithNetwork`, `getNetworks`, `test_returns_correct_structure`, `test_returns_empty_when_no_networks`, `test_only_networks_with_active_screens_are_returned`, `test_screen_count_is_correct`, `test_sorted_by_screen_count_desc`, `test_result_is_cached`, `test_unauthenticated_is_rejected` |
| [tests/Feature/Api/InventoryOwnersTest.php](../../tests/Feature/Api/InventoryOwnersTest.php) | `setUp`, `makeOwnerWithScreens`, `test_owners_returns_correct_structure`, `test_owner_id_is_slug`, `test_only_active_owners_are_returned`, `test_featured_filter_returns_only_featured_owners`, `test_screen_count_is_correct`, `test_screen_count_excludes_inactive_screens`, `test_city_count_counts_distinct_cities`, `test_venue_types_returns_distinct_types`, `test_owner_detail_returns_correct_structure`, `test_owner_detail_includes_about_field`, `test_owner_detail_404_for_unknown_slug`, `test_owner_detail_404_for_non_active_owner`, `test_owner_detail_does_not_have_about_field_in_list`, `test_q_filters_by_owner_name`, `test_q_returns_empty_when_no_match`, `test_q_validates_max_100_chars`, `test_owners_unauthenticated_is_rejected`, `test_owner_detail_unauthenticated_is_rejected` |
| [tests/Feature/Api/InventoryScreensFilterTest.php](../../tests/Feature/Api/InventoryScreensFilterTest.php) | `setUp`, `getScreens`, `makeScreen`, `test_status_active_returns_only_active_screens`, `test_city_slug_matches_vietnamese_name_in_db`, `test_city_array_filter_returns_matching_screens`, `test_city_pipe_separated_backward_compat`, `test_city_single_value_backward_compat`, `test_venue_type_array_filter`, `test_screen_type_lcd_filter`, `test_screen_type_led_filter`, `test_screen_type_billboard_filter`, `test_screen_type_multi_value_lcd_and_led`, `test_orientation_landscape_filter`, `test_orientation_portrait_filter`, `test_orientation_multi_value`, `test_orientation_invalid_value_returns_all`, `test_q_searches_screen_name`, `test_q_searches_site_city`, `test_q_empty_returns_all`, `test_sort_price_asc`, `test_sort_price_desc`, `test_sort_invalid_value_does_not_error`, `test_response_has_correct_structure`, `test_network_filter_returns_matching_screens`, `test_network_filter_multi_value_returns_union`, `test_network_filter_nonexistent_code_returns_empty`, `test_owner_filter_returns_screens_by_owner_slug`, `test_owner_filter_nonexistent_slug_returns_empty`, `test_district_filter_returns_matching_screens`, `test_district_filter_multi_value`, `test_region_filter_returns_screens_in_provinces_of_that_region`, `test_region_filter_invalid_value_returns_422`, `test_region_filter_multi_value`, `test_unauthenticated_request_is_rejected` |
| [tests/Feature/Api/InventoryScreensMapTest.php](../../tests/Feature/Api/InventoryScreensMapTest.php) | `setUp`, `getMap`, `makeScreen`, `test_map_returns_correct_structure`, `test_map_has_no_page_or_limit_in_response`, `test_map_returns_all_screens_without_pagination`, `test_map_item_has_only_lightweight_fields`, `test_map_route_is_not_matched_by_screen_id_route`, `test_map_filters_by_city_array`, `test_map_filters_by_venue_type`, `test_map_filters_by_status`, `test_map_filters_by_orientation`, `test_map_size_field_format`, `test_map_size_null_when_no_spec_dimensions`, `test_map_unauthenticated_is_rejected` |
| [tests/Feature/Api/InventoryStatsTest.php](../../tests/Feature/Api/InventoryStatsTest.php) | `setUp`, `getStats`, `makeActiveScreen`, `test_stats_returns_correct_structure`, `test_total_screens_counts_only_active`, `test_total_cities_counts_distinct_cities`, `test_venues_groups_by_venue_type`, `test_venues_has_correct_labels`, `test_venues_fallback_label_when_not_in_taxonomy`, `test_cities_returns_exactly_5_fixed_cities`, `test_cities_counts_screens_by_vietnamese_city_name`, `test_stats_is_cached`, `test_unauthenticated_is_rejected` |
| [tests/Feature/Api/InventoryVenueTypesTest.php](../../tests/Feature/Api/InventoryVenueTypesTest.php) | `setUp`, `seedOutdoorTaxonomy`, `makeActiveScreen`, `getVenueTypes`, `test_returns_correct_structure`, `test_returns_empty_when_no_active_screens`, `test_only_includes_types_with_active_screens`, `test_inactive_screens_are_excluded`, `test_parent_count_is_rolled_up_from_children`, `test_children_are_nested_correctly`, `test_leaf_node_has_empty_children`, `test_rollup_aggregates_multiple_leaf_types`, `test_orphan_type_not_in_taxonomy_is_returned_flat`, `test_orphan_fallback_label_uses_ucfirst`, `test_result_is_cached`, `test_unauthenticated_is_rejected` |
| [tests/Feature/Buyer/OrgUserPermissionTest.php](../../tests/Feature/Buyer/OrgUserPermissionTest.php) | `setUp`, `makeMember`, `test_assignable_roles_excludes_admin_for_non_super_admin`, `test_org_admin_can_view_and_create`, `test_planner_cannot_view_or_create`, `test_viewer_cannot_view`, `test_org_admin_cannot_update_record_with_role_admin`, `test_org_admin_can_update_planner_record`, `test_org_admin_cannot_update_self`, `test_super_admin_can_update_admin_record` |
| [tests/Feature/ExampleTest.php](../../tests/Feature/ExampleTest.php) | `test_the_application_returns_a_successful_response` |
| [tests/Feature/InvitationFlowTest.php](../../tests/Feature/InvitationFlowTest.php) | `setUp`, `test_invite_creates_row_and_sends_notification`, `test_invite_revokes_pending_duplicate_for_same_tenant`, `test_invite_blocks_existing_member`, `test_accept_creates_user_with_pivot_and_role`, `test_accept_attaches_existing_user_to_pivot_only`, `test_accept_rejects_expired_token`, `test_accept_rejects_already_accepted_token`, `test_invite_rejects_invalid_role_for_tenant_type`, `test_show_route_renders_accept_page`, `test_show_route_returns_410_for_expired`, `test_store_route_creates_user_and_logs_in` |
| [tests/Feature/LivewireUploadSecurityTest.php](../../tests/Feature/LivewireUploadSecurityTest.php) | `passes`, `realFile`, `test_endpoint_upload_tu_choi_file_php`, `test_endpoint_upload_van_nhan_anh_hop_le`, `test_tu_choi_webshell_doi_duoi_thanh_anh`, `test_tu_choi_cac_duoi_php_khac`, `test_tu_choi_svg`, `test_nhan_anh`, `test_nhan_pdf_giay_phep_kinh_doanh`, `test_nhan_excel_va_csv_de_import`, `test_lenh_don_xoa_file_cu_va_giu_file_moi`, `test_lenh_don_duoc_dat_lich` |
| [tests/Feature/OwnerRevenueShareHiddenTest.php](../../tests/Feature/OwnerRevenueShareHiddenTest.php) | `setUp`, `test_danh_sach_media_owner_khong_co_cot_rev_share`, `test_form_media_owner_khong_co_truong_revenue_share` |
| [tests/Feature/OwnerReviewTest.php](../../tests/Feature/OwnerReviewTest.php) | `setUp`, `makeOrg`, `makeMemberOf`, `makeCampaign`, `test_campaign_da_chay_xong_thi_duoc_danh_gia_owner`, `test_campaign_con_la_nhap_thi_khong_duoc_danh_gia`, `test_khong_danh_gia_duoc_owner_khong_co_trong_campaign`, `test_moi_campaign_chi_danh_gia_moi_owner_mot_lan`, `test_danh_gia_moi_chua_hien_ngay`, `test_diem_trung_binh_chi_tinh_danh_gia_da_duyet`, `test_owner_chua_co_danh_gia_thi_khong_co_diem`, `test_gui_danh_gia_qua_form`, `test_khong_danh_gia_duoc_campaign_cua_to_chuc_khac`, `test_so_sao_ngoai_khoang_1_5_bi_tu_choi` |
| [tests/Feature/PanelAccessTest.php](../../tests/Feature/PanelAccessTest.php) | `setUp`, `test_user_model_implements_filament_user_contract`, `test_super_admin_can_access_admin_panel`, `test_publisher_cannot_access_admin_panel`, `test_buyer_cannot_access_admin_panel`, `test_publisher_with_active_owner_can_access_publisher_panel`, `test_publisher_without_owner_record_cannot_access_publisher_panel`, `test_publisher_with_inactive_owner_cannot_access_publisher_panel`, `test_super_admin_cannot_access_publisher_panel`, `test_buyer_cannot_access_publisher_panel`, `test_buyer_with_active_org_can_access_buyer_panel`, `test_buyer_without_org_record_cannot_access_buyer_panel`, `test_publisher_cannot_access_buyer_panel`, `test_user_with_no_role_cannot_access_any_panel`, `test_unknown_panel_id_always_denied`, `makePublisherWithActiveOwner`, `makeBuyerWithActiveOrg`, `canAccess` |
| [tests/Feature/PaymentPerOwnerTest.php](../../tests/Feature/PaymentPerOwnerTest.php) | `setUp`, `makeOwner`, `makeCampaign`, `test_chia_so_tien_theo_tung_media_owner`, `test_tong_cac_phan_bang_tong_campaign`, `test_thanh_toan_cho_owner_nay_khong_lam_owner_kia_thanh_da_tra`, `test_payment_ghi_nhan_dung_owner_nhan_tien`, `paymentUrl`, `test_trang_thanh_toan_hien_tai_khoan_cua_media_owner`, `test_trang_thanh_toan_khong_con_tai_khoan_gia_cua_san`, `test_owner_chua_khai_tai_khoan_thi_noi_thang_ra`, `test_khong_tick_dong_y_quy_che_thi_khong_xac_nhan_duoc`, `test_khong_tra_duoc_cho_owner_khong_co_trong_campaign` |
| [tests/Feature/PolicyConsentTest.php](../../tests/Feature/PolicyConsentTest.php) | `domain`, `registerPayload`, `test_dang_ky_khong_tick_dong_y_thi_bi_tu_choi`, `test_dang_ky_co_tick_thi_ghi_lai_bang_chung_chap_thuan`, `test_ban_ghi_chap_thuan_dong_dau_dung_phien_ban_chinh_sach`, `test_dang_ky_that_bai_thi_khong_de_lai_ban_ghi_chap_thuan_mo_coi`, `test_key_chinh_sach_sai_thi_no_ngay_thay_vi_ghi_ban_ghi_vo_nghia`, `test_phien_ban_luon_lay_tu_config_khong_lay_tu_client`, `test_ghi_lai_ip_va_user_agent`, `test_ghi_nhieu_chinh_sach_cung_luc` |
| [tests/Feature/PolicyPagesTest.php](../../tests/Feature/PolicyPagesTest.php) | `domain`, `get_`, `test_trang_chinh_sach_tai_duoc`, `policySlugs`, `test_bang_phi_neu_ro_muc_phi_va_doi_tuong_thu`, `test_bang_phi_da_ban_hanh_khong_hien_canh_bao_ban_nhap`, `test_bang_phi_khong_noi_toi_chia_doanh_thu`, `test_chan_trang_co_link_bang_phi`, `test_chinh_sach_bao_mat_neu_viec_chia_se_lien_he_cho_media_owner`, `test_slug_chinh_sach_khong_ton_tai_thi_khong_render_trang_chinh_sach`, `test_route_chinh_sach_khong_nuot_cac_duong_dan_khac`, `test_trang_chinh_sach_noi_ro_la_ban_nhap_khi_chua_ban_hanh`, `test_khi_da_ban_hanh_thi_khong_con_canh_bao_ban_nhap`, `test_chan_trang_co_day_du_thong_tin_phap_nhan`, `test_chan_trang_co_du_nam_link_chinh_sach`, `test_chan_trang_khong_con_link_chet`, `test_pop_up_thu_nghiem_hien_tren_trang_chu`, `test_pop_up_thu_nghiem_hien_ca_o_trang_dang_ky`, `test_tat_trial_mode_thi_pop_up_bien_mat_toan_site` |
| [tests/Feature/PublicReflectionTest.php](../../tests/Feature/PublicReflectionTest.php) | `payload`, `domain`, `test_gui_phan_anh_thi_duoc_ghi_nhan_va_tra_ma_tra_cuu`, `test_phan_anh_moi_khong_tu_dong_len_trang_cong_khai`, `test_thieu_email_lien_he_thi_khong_nhan`, `test_noi_dung_qua_ngan_thi_khong_nhan`, `test_bot_dien_vao_bay_spam_thi_bi_tu_choi`, `test_danh_sach_cong_khai_chi_hien_phan_anh_da_duoc_dang`, `test_trang_cong_khai_khong_lo_email_va_dien_thoai_nguoi_gui`, `test_ghi_chu_noi_bo_khong_bao_gio_ra_trang_cong_khai`, `test_serialize_model_khong_kem_du_lieu_ca_nhan`, `test_ma_tra_cuu_tang_dan_va_khong_trung` |
| [tests/Feature/PublicVisibilityGateTest.php](../../tests/Feature/PublicVisibilityGateTest.php) | `screenFor`, `test_screen_cua_owner_pending_khong_hien_cong_khai`, `test_screen_cua_owner_suspended_khong_hien_cong_khai`, `test_owner_bi_xoa_mem_thi_screen_khong_hien`, `test_screen_cua_owner_active_van_hien_binh_thuong`, `test_owner_active_nhung_screen_tat_thi_khong_hien`, `test_listing_khong_tra_ve_screen_cua_owner_pending`, `test_trang_chi_tiet_screen_cua_owner_pending_tra_ve_null`, `test_hero_stats_khong_dem_screen_cua_owner_pending`, `test_product_cua_owner_pending_khong_hien_cong_khai` |
| [tests/Feature/Publisher/BookingInboxBuyerContactTest.php](../../tests/Feature/Publisher/BookingInboxBuyerContactTest.php) | `setUp`, `publisherOf`, `viewUrl`, `test_owner_co_man_hinh_trong_booking_thay_lien_he_nguoi_mua`, `test_owner_khong_co_man_hinh_trong_booking_khong_mo_duoc_trang`, `test_booking_con_nhap_chua_gui_thi_owner_khong_thay` |
| [tests/Feature/Publisher/OwnerUserPermissionTest.php](../../tests/Feature/Publisher/OwnerUserPermissionTest.php) | `setUp`, `makeMember`, `test_assignable_roles_excludes_owner_for_non_super_admin`, `test_super_admin_can_create_owner_user`, `test_tenant_owner_can_create_owner_user`, `test_manager_cannot_create_owner_user`, `test_read_only_cannot_view_team_list`, `test_manager_cannot_view_team_list`, `test_tenant_owner_can_view_team_list`, `test_tenant_owner_cannot_update_a_record_with_role_owner`, `test_tenant_owner_can_update_manager_record`, `test_tenant_owner_cannot_update_self`, `test_super_admin_can_update_record_with_role_owner` |
| [tests/TestCase.php](../../tests/TestCase.php) |  |
| [tests/Unit/ExampleTest.php](../../tests/Unit/ExampleTest.php) | `test_that_true_is_true` |

## Filament resources/pages

- [app/Filament/Buyer/Resources/OrgUserResource/Pages/EditOrgUser.php](../../app/Filament/Buyer/Resources/OrgUserResource/Pages/EditOrgUser.php)
- [app/Filament/Buyer/Resources/OrgUserResource/Pages/ListOrgUsers.php](../../app/Filament/Buyer/Resources/OrgUserResource/Pages/ListOrgUsers.php)
- [app/Filament/Buyer/Resources/OrgUserResource.php](../../app/Filament/Buyer/Resources/OrgUserResource.php)
- [app/Filament/Pages/OohxAnalytics.php](../../app/Filament/Pages/OohxAnalytics.php)
- [app/Filament/Pages/OohxCollectors.php](../../app/Filament/Pages/OohxCollectors.php)
- [app/Filament/Pages/OohxDataEngine.php](../../app/Filament/Pages/OohxDataEngine.php)
- [app/Filament/Pages/OohxHealth.php](../../app/Filament/Pages/OohxHealth.php)
- [app/Filament/Pages/PermissionMatrix.php](../../app/Filament/Pages/PermissionMatrix.php)
- [app/Filament/Publisher/Pages/CompanyProfile.php](../../app/Filament/Publisher/Pages/CompanyProfile.php)
- [app/Filament/Publisher/Pages/ImportSites.php](../../app/Filament/Publisher/Pages/ImportSites.php)
- [app/Filament/Publisher/Resources/BookingInboxResource/Pages/ListBookingInbox.php](../../app/Filament/Publisher/Resources/BookingInboxResource/Pages/ListBookingInbox.php)
- [app/Filament/Publisher/Resources/BookingInboxResource/Pages/ViewBookingInbox.php](../../app/Filament/Publisher/Resources/BookingInboxResource/Pages/ViewBookingInbox.php)
- [app/Filament/Publisher/Resources/BookingInboxResource.php](../../app/Filament/Publisher/Resources/BookingInboxResource.php)
- [app/Filament/Publisher/Resources/NetworkResource/Pages/CreateNetwork.php](../../app/Filament/Publisher/Resources/NetworkResource/Pages/CreateNetwork.php)
- [app/Filament/Publisher/Resources/NetworkResource/Pages/EditNetwork.php](../../app/Filament/Publisher/Resources/NetworkResource/Pages/EditNetwork.php)
- [app/Filament/Publisher/Resources/NetworkResource/Pages/ListNetwork.php](../../app/Filament/Publisher/Resources/NetworkResource/Pages/ListNetwork.php)
- [app/Filament/Publisher/Resources/NetworkResource/Pages/ViewNetwork.php](../../app/Filament/Publisher/Resources/NetworkResource/Pages/ViewNetwork.php)
- [app/Filament/Publisher/Resources/NetworkResource.php](../../app/Filament/Publisher/Resources/NetworkResource.php)
- [app/Filament/Publisher/Resources/OwnerUserResource/Pages/EditOwnerUser.php](../../app/Filament/Publisher/Resources/OwnerUserResource/Pages/EditOwnerUser.php)
- [app/Filament/Publisher/Resources/OwnerUserResource/Pages/ListOwnerUsers.php](../../app/Filament/Publisher/Resources/OwnerUserResource/Pages/ListOwnerUsers.php)
- [app/Filament/Publisher/Resources/OwnerUserResource.php](../../app/Filament/Publisher/Resources/OwnerUserResource.php)
- [app/Filament/Publisher/Resources/ProductResource/Pages/CreateProduct.php](../../app/Filament/Publisher/Resources/ProductResource/Pages/CreateProduct.php)
- [app/Filament/Publisher/Resources/ProductResource/Pages/EditProduct.php](../../app/Filament/Publisher/Resources/ProductResource/Pages/EditProduct.php)
- [app/Filament/Publisher/Resources/ProductResource/Pages/ListProducts.php](../../app/Filament/Publisher/Resources/ProductResource/Pages/ListProducts.php)
- [app/Filament/Publisher/Resources/ProductResource.php](../../app/Filament/Publisher/Resources/ProductResource.php)
- [app/Filament/Publisher/Resources/ScreenResource/Pages/CreateScreen.php](../../app/Filament/Publisher/Resources/ScreenResource/Pages/CreateScreen.php)
- [app/Filament/Publisher/Resources/ScreenResource/Pages/EditScreen.php](../../app/Filament/Publisher/Resources/ScreenResource/Pages/EditScreen.php)
- [app/Filament/Publisher/Resources/ScreenResource/Pages/ListScreen.php](../../app/Filament/Publisher/Resources/ScreenResource/Pages/ListScreen.php)
- [app/Filament/Publisher/Resources/ScreenResource/Pages/ViewScreen.php](../../app/Filament/Publisher/Resources/ScreenResource/Pages/ViewScreen.php)
- [app/Filament/Publisher/Resources/ScreenResource.php](../../app/Filament/Publisher/Resources/ScreenResource.php)
- [app/Filament/Publisher/Resources/SiteResource/Pages/CreateSite.php](../../app/Filament/Publisher/Resources/SiteResource/Pages/CreateSite.php)
- [app/Filament/Publisher/Resources/SiteResource/Pages/EditSite.php](../../app/Filament/Publisher/Resources/SiteResource/Pages/EditSite.php)
- [app/Filament/Publisher/Resources/SiteResource/Pages/ListSite.php](../../app/Filament/Publisher/Resources/SiteResource/Pages/ListSite.php)
- [app/Filament/Publisher/Resources/SiteResource/Pages/ViewSite.php](../../app/Filament/Publisher/Resources/SiteResource/Pages/ViewSite.php)
- [app/Filament/Publisher/Resources/SiteResource.php](../../app/Filament/Publisher/Resources/SiteResource.php)
- [app/Filament/Resources/CampaignResource/Pages/ListCampaigns.php](../../app/Filament/Resources/CampaignResource/Pages/ListCampaigns.php)
- [app/Filament/Resources/CampaignResource/Pages/ViewCampaign.php](../../app/Filament/Resources/CampaignResource/Pages/ViewCampaign.php)
- [app/Filament/Resources/CampaignResource.php](../../app/Filament/Resources/CampaignResource.php)
- [app/Filament/Resources/CreativeResource/Pages/ListCreatives.php](../../app/Filament/Resources/CreativeResource/Pages/ListCreatives.php)
- [app/Filament/Resources/CreativeResource.php](../../app/Filament/Resources/CreativeResource.php)
- [app/Filament/Resources/NetworkResource/Pages/CreateNetwork.php](../../app/Filament/Resources/NetworkResource/Pages/CreateNetwork.php)
- [app/Filament/Resources/NetworkResource/Pages/EditNetwork.php](../../app/Filament/Resources/NetworkResource/Pages/EditNetwork.php)
- [app/Filament/Resources/NetworkResource/Pages/ListNetworks.php](../../app/Filament/Resources/NetworkResource/Pages/ListNetworks.php)
- [app/Filament/Resources/NetworkResource/Pages/ViewNetwork.php](../../app/Filament/Resources/NetworkResource/Pages/ViewNetwork.php)
- [app/Filament/Resources/NetworkResource.php](../../app/Filament/Resources/NetworkResource.php)
- [app/Filament/Resources/OohxCampaignEstimateResource/Pages/CreateOohxCampaignEstimate.php](../../app/Filament/Resources/OohxCampaignEstimateResource/Pages/CreateOohxCampaignEstimate.php)
- [app/Filament/Resources/OohxCampaignEstimateResource/Pages/ListOohxCampaignEstimates.php](../../app/Filament/Resources/OohxCampaignEstimateResource/Pages/ListOohxCampaignEstimates.php)
- [app/Filament/Resources/OohxCampaignEstimateResource/Pages/ViewOohxCampaignEstimate.php](../../app/Filament/Resources/OohxCampaignEstimateResource/Pages/ViewOohxCampaignEstimate.php)
- [app/Filament/Resources/OohxCampaignEstimateResource.php](../../app/Filament/Resources/OohxCampaignEstimateResource.php)
- [app/Filament/Resources/OohxCollectorRunResource/Pages/ListCollectorRuns.php](../../app/Filament/Resources/OohxCollectorRunResource/Pages/ListCollectorRuns.php)
- [app/Filament/Resources/OohxCollectorRunResource/Pages/ViewCollectorRun.php](../../app/Filament/Resources/OohxCollectorRunResource/Pages/ViewCollectorRun.php)
- [app/Filament/Resources/OohxCollectorRunResource.php](../../app/Filament/Resources/OohxCollectorRunResource.php)
- [app/Filament/Resources/OohxConfig/AuditLogResource/Pages/ListAuditLog.php](../../app/Filament/Resources/OohxConfig/AuditLogResource/Pages/ListAuditLog.php)
- [app/Filament/Resources/OohxConfig/AuditLogResource/Pages/ViewAuditEntry.php](../../app/Filament/Resources/OohxConfig/AuditLogResource/Pages/ViewAuditEntry.php)
- [app/Filament/Resources/OohxConfig/AuditLogResource.php](../../app/Filament/Resources/OohxConfig/AuditLogResource.php)
- [app/Filament/Resources/OohxConfig/BaseCityTrafficResource/Pages/ListBaseCityTraffic.php](../../app/Filament/Resources/OohxConfig/BaseCityTrafficResource/Pages/ListBaseCityTraffic.php)
- [app/Filament/Resources/OohxConfig/BaseCityTrafficResource.php](../../app/Filament/Resources/OohxConfig/BaseCityTrafficResource.php)
- [app/Filament/Resources/OohxConfig/DeliveryDefaultResource/Pages/ListDeliveryDefaults.php](../../app/Filament/Resources/OohxConfig/DeliveryDefaultResource/Pages/ListDeliveryDefaults.php)
- [app/Filament/Resources/OohxConfig/DeliveryDefaultResource.php](../../app/Filament/Resources/OohxConfig/DeliveryDefaultResource.php)
- [app/Filament/Resources/OohxConfig/FormulaVersionResource/Pages/ListFormulaVersions.php](../../app/Filament/Resources/OohxConfig/FormulaVersionResource/Pages/ListFormulaVersions.php)
- [app/Filament/Resources/OohxConfig/FormulaVersionResource/Pages/ViewFormulaVersion.php](../../app/Filament/Resources/OohxConfig/FormulaVersionResource/Pages/ViewFormulaVersion.php)
- [app/Filament/Resources/OohxConfig/FormulaVersionResource.php](../../app/Filament/Resources/OohxConfig/FormulaVersionResource.php)
- [app/Filament/Resources/OohxConfig/RoadClassMultiplierResource/Pages/ListRoadClassMultipliers.php](../../app/Filament/Resources/OohxConfig/RoadClassMultiplierResource/Pages/ListRoadClassMultipliers.php)
- [app/Filament/Resources/OohxConfig/RoadClassMultiplierResource.php](../../app/Filament/Resources/OohxConfig/RoadClassMultiplierResource.php)
- [app/Filament/Resources/OohxConfig/SeasonalityFactorResource/Pages/ListSeasonalityFactors.php](../../app/Filament/Resources/OohxConfig/SeasonalityFactorResource/Pages/ListSeasonalityFactors.php)
- [app/Filament/Resources/OohxConfig/SeasonalityFactorResource/Pages/SeasonalityHeatmap.php](../../app/Filament/Resources/OohxConfig/SeasonalityFactorResource/Pages/SeasonalityHeatmap.php)
- [app/Filament/Resources/OohxConfig/SeasonalityFactorResource.php](../../app/Filament/Resources/OohxConfig/SeasonalityFactorResource.php)
- [app/Filament/Resources/OohxConfig/ZoneFactorResource/Pages/ListZoneFactors.php](../../app/Filament/Resources/OohxConfig/ZoneFactorResource/Pages/ListZoneFactors.php)
- [app/Filament/Resources/OohxConfig/ZoneFactorResource.php](../../app/Filament/Resources/OohxConfig/ZoneFactorResource.php)
- [app/Filament/Resources/OohxEstimateResource/Pages/ListOohxEstimates.php](../../app/Filament/Resources/OohxEstimateResource/Pages/ListOohxEstimates.php)
- [app/Filament/Resources/OohxEstimateResource/Pages/ViewOohxEstimate.php](../../app/Filament/Resources/OohxEstimateResource/Pages/ViewOohxEstimate.php)
- [app/Filament/Resources/OohxEstimateResource.php](../../app/Filament/Resources/OohxEstimateResource.php)
- [app/Filament/Resources/OohxRecomputeJobResource/Pages/ListRecomputeJobs.php](../../app/Filament/Resources/OohxRecomputeJobResource/Pages/ListRecomputeJobs.php)
- [app/Filament/Resources/OohxRecomputeJobResource/Pages/ViewRecomputeJob.php](../../app/Filament/Resources/OohxRecomputeJobResource/Pages/ViewRecomputeJob.php)
- [app/Filament/Resources/OohxRecomputeJobResource.php](../../app/Filament/Resources/OohxRecomputeJobResource.php)
- [app/Filament/Resources/OrganizationResource/Pages/CreateOrganization.php](../../app/Filament/Resources/OrganizationResource/Pages/CreateOrganization.php)
- [app/Filament/Resources/OrganizationResource/Pages/EditOrganization.php](../../app/Filament/Resources/OrganizationResource/Pages/EditOrganization.php)
- [app/Filament/Resources/OrganizationResource/Pages/ListOrganizations.php](../../app/Filament/Resources/OrganizationResource/Pages/ListOrganizations.php)
- [app/Filament/Resources/OrganizationResource/Pages/ViewOrganization.php](../../app/Filament/Resources/OrganizationResource/Pages/ViewOrganization.php)
- [app/Filament/Resources/OrganizationResource.php](../../app/Filament/Resources/OrganizationResource.php)
- [app/Filament/Resources/OwnerResource/Pages/CreateOwner.php](../../app/Filament/Resources/OwnerResource/Pages/CreateOwner.php)
- [app/Filament/Resources/OwnerResource/Pages/EditOwner.php](../../app/Filament/Resources/OwnerResource/Pages/EditOwner.php)
- [app/Filament/Resources/OwnerResource/Pages/ListOwners.php](../../app/Filament/Resources/OwnerResource/Pages/ListOwners.php)
- [app/Filament/Resources/OwnerResource.php](../../app/Filament/Resources/OwnerResource.php)
- [app/Filament/Resources/OwnerReviewResource/Pages/EditOwnerReview.php](../../app/Filament/Resources/OwnerReviewResource/Pages/EditOwnerReview.php)
- [app/Filament/Resources/OwnerReviewResource/Pages/ListOwnerReviews.php](../../app/Filament/Resources/OwnerReviewResource/Pages/ListOwnerReviews.php)
- [app/Filament/Resources/OwnerReviewResource.php](../../app/Filament/Resources/OwnerReviewResource.php)
- [app/Filament/Resources/PoiSnapshotResource/Pages/ListPoiSnapshots.php](../../app/Filament/Resources/PoiSnapshotResource/Pages/ListPoiSnapshots.php)
- [app/Filament/Resources/PoiSnapshotResource/Pages/ViewPoiSnapshot.php](../../app/Filament/Resources/PoiSnapshotResource/Pages/ViewPoiSnapshot.php)
- [app/Filament/Resources/PoiSnapshotResource.php](../../app/Filament/Resources/PoiSnapshotResource.php)
- [app/Filament/Resources/ProductResource/Pages/CreateProduct.php](../../app/Filament/Resources/ProductResource/Pages/CreateProduct.php)
- [app/Filament/Resources/ProductResource/Pages/EditProduct.php](../../app/Filament/Resources/ProductResource/Pages/EditProduct.php)
- [app/Filament/Resources/ProductResource/Pages/ListProducts.php](../../app/Filament/Resources/ProductResource/Pages/ListProducts.php)
- [app/Filament/Resources/ProductResource/Pages/ViewProduct.php](../../app/Filament/Resources/ProductResource/Pages/ViewProduct.php)
- [app/Filament/Resources/ProductResource.php](../../app/Filament/Resources/ProductResource.php)
- [app/Filament/Resources/PublicReflectionResource/Pages/EditPublicReflection.php](../../app/Filament/Resources/PublicReflectionResource/Pages/EditPublicReflection.php)
- [app/Filament/Resources/PublicReflectionResource/Pages/ListPublicReflections.php](../../app/Filament/Resources/PublicReflectionResource/Pages/ListPublicReflections.php)
- [app/Filament/Resources/PublicReflectionResource.php](../../app/Filament/Resources/PublicReflectionResource.php)
- [app/Filament/Resources/RoleResource/Pages/CreateRole.php](../../app/Filament/Resources/RoleResource/Pages/CreateRole.php)
- [app/Filament/Resources/RoleResource/Pages/EditRole.php](../../app/Filament/Resources/RoleResource/Pages/EditRole.php)
- [app/Filament/Resources/RoleResource/Pages/ListRoles.php](../../app/Filament/Resources/RoleResource/Pages/ListRoles.php)
- [app/Filament/Resources/RoleResource/Pages/ViewRole.php](../../app/Filament/Resources/RoleResource/Pages/ViewRole.php)
- [app/Filament/Resources/RoleResource.php](../../app/Filament/Resources/RoleResource.php)
- [app/Filament/Resources/ScreenImportResource/Pages/ListScreenImports.php](../../app/Filament/Resources/ScreenImportResource/Pages/ListScreenImports.php)
- [app/Filament/Resources/ScreenImportResource/Pages/ViewScreenImport.php](../../app/Filament/Resources/ScreenImportResource/Pages/ViewScreenImport.php)
- [app/Filament/Resources/ScreenImportResource.php](../../app/Filament/Resources/ScreenImportResource.php)
- [app/Filament/Resources/ScreenResource/Pages/CreateScreen.php](../../app/Filament/Resources/ScreenResource/Pages/CreateScreen.php)
- [app/Filament/Resources/ScreenResource/Pages/EditScreen.php](../../app/Filament/Resources/ScreenResource/Pages/EditScreen.php)
- [app/Filament/Resources/ScreenResource/Pages/ListScreen.php](../../app/Filament/Resources/ScreenResource/Pages/ListScreen.php)
- [app/Filament/Resources/ScreenResource/Pages/ViewScreen.php](../../app/Filament/Resources/ScreenResource/Pages/ViewScreen.php)
- [app/Filament/Resources/ScreenResource.php](../../app/Filament/Resources/ScreenResource.php)
- [app/Filament/Resources/SiteResource/Pages/CreateSite.php](../../app/Filament/Resources/SiteResource/Pages/CreateSite.php)
- [app/Filament/Resources/SiteResource/Pages/EditSite.php](../../app/Filament/Resources/SiteResource/Pages/EditSite.php)
- [app/Filament/Resources/SiteResource/Pages/ListSites.php](../../app/Filament/Resources/SiteResource/Pages/ListSites.php)
- [app/Filament/Resources/SiteResource/Pages/ViewSite.php](../../app/Filament/Resources/SiteResource/Pages/ViewSite.php)
- [app/Filament/Resources/SiteResource.php](../../app/Filament/Resources/SiteResource.php)
- [app/Filament/Resources/UserResource/Pages/CreateUser.php](../../app/Filament/Resources/UserResource/Pages/CreateUser.php)
- [app/Filament/Resources/UserResource/Pages/EditUser.php](../../app/Filament/Resources/UserResource/Pages/EditUser.php)
- [app/Filament/Resources/UserResource/Pages/ListUsers.php](../../app/Filament/Resources/UserResource/Pages/ListUsers.php)
- [app/Filament/Resources/UserResource/Pages/ViewUser.php](../../app/Filament/Resources/UserResource/Pages/ViewUser.php)
- [app/Filament/Resources/UserResource.php](../../app/Filament/Resources/UserResource.php)
- [app/Filament/Resources/VenueCategoryResource/Pages/CreateVenueCategory.php](../../app/Filament/Resources/VenueCategoryResource/Pages/CreateVenueCategory.php)
- [app/Filament/Resources/VenueCategoryResource/Pages/EditVenueCategory.php](../../app/Filament/Resources/VenueCategoryResource/Pages/EditVenueCategory.php)
- [app/Filament/Resources/VenueCategoryResource/Pages/ListVenueCategories.php](../../app/Filament/Resources/VenueCategoryResource/Pages/ListVenueCategories.php)
- [app/Filament/Resources/VenueCategoryResource.php](../../app/Filament/Resources/VenueCategoryResource.php)
- [app/Filament/Resources/VenueTypeResource/Pages/CreateVenueType.php](../../app/Filament/Resources/VenueTypeResource/Pages/CreateVenueType.php)
- [app/Filament/Resources/VenueTypeResource/Pages/EditVenueType.php](../../app/Filament/Resources/VenueTypeResource/Pages/EditVenueType.php)
- [app/Filament/Resources/VenueTypeResource/Pages/ListVenueType.php](../../app/Filament/Resources/VenueTypeResource/Pages/ListVenueType.php)
- [app/Filament/Resources/VenueTypeResource.php](../../app/Filament/Resources/VenueTypeResource.php)
- [app/Filament/Resources/VietnamCommuneResource/Pages/CreateVietnamCommune.php](../../app/Filament/Resources/VietnamCommuneResource/Pages/CreateVietnamCommune.php)
- [app/Filament/Resources/VietnamCommuneResource/Pages/EditVietnamCommune.php](../../app/Filament/Resources/VietnamCommuneResource/Pages/EditVietnamCommune.php)
- [app/Filament/Resources/VietnamCommuneResource/Pages/ListVietnamCommunes.php](../../app/Filament/Resources/VietnamCommuneResource/Pages/ListVietnamCommunes.php)
- [app/Filament/Resources/VietnamCommuneResource.php](../../app/Filament/Resources/VietnamCommuneResource.php)
- [app/Filament/Resources/VietnamProvinceResource/Pages/CreateVietnamProvince.php](../../app/Filament/Resources/VietnamProvinceResource/Pages/CreateVietnamProvince.php)
- [app/Filament/Resources/VietnamProvinceResource/Pages/EditVietnamProvince.php](../../app/Filament/Resources/VietnamProvinceResource/Pages/EditVietnamProvince.php)
- [app/Filament/Resources/VietnamProvinceResource/Pages/ListVietnamProvinces.php](../../app/Filament/Resources/VietnamProvinceResource/Pages/ListVietnamProvinces.php)
- [app/Filament/Resources/VietnamProvinceResource.php](../../app/Filament/Resources/VietnamProvinceResource.php)
- [app/Filament/Resources/VietnamRegionResource/Pages/CreateVietnamRegion.php](../../app/Filament/Resources/VietnamRegionResource/Pages/CreateVietnamRegion.php)
- [app/Filament/Resources/VietnamRegionResource/Pages/EditVietnamRegion.php](../../app/Filament/Resources/VietnamRegionResource/Pages/EditVietnamRegion.php)
- [app/Filament/Resources/VietnamRegionResource/Pages/ListVietnamRegions.php](../../app/Filament/Resources/VietnamRegionResource/Pages/ListVietnamRegions.php)
- [app/Filament/Resources/VietnamRegionResource.php](../../app/Filament/Resources/VietnamRegionResource.php)
- [app/Filament/Shared/Pages/BaseViewNetwork.php](../../app/Filament/Shared/Pages/BaseViewNetwork.php)
- [app/Filament/Shared/Resources/BaseNetworkResource.php](../../app/Filament/Shared/Resources/BaseNetworkResource.php)
- [app/Filament/Shared/Resources/BaseScreenResource.php](../../app/Filament/Shared/Resources/BaseScreenResource.php)
- [app/Filament/Shared/Resources/BaseSiteResource.php](../../app/Filament/Shared/Resources/BaseSiteResource.php)

## Migrations

- [database/migrations/0001_01_01_000000_create_users_table.php](../../database/migrations/0001_01_01_000000_create_users_table.php) ? creates users, password_reset_tokens, sessions
- [database/migrations/0001_01_01_000001_create_cache_table.php](../../database/migrations/0001_01_01_000001_create_cache_table.php) ? creates cache, cache_locks
- [database/migrations/0001_01_01_000002_create_jobs_table.php](../../database/migrations/0001_01_01_000002_create_jobs_table.php) ? creates jobs, job_batches, failed_jobs
- [database/migrations/2025_01_01_000001_create_owners_table.php](../../database/migrations/2025_01_01_000001_create_owners_table.php) ? creates owners
- [database/migrations/2025_01_01_000002_create_owner_users_table.php](../../database/migrations/2025_01_01_000002_create_owner_users_table.php) ? creates owner_users
- [database/migrations/2025_01_01_000003_create_networks_table.php](../../database/migrations/2025_01_01_000003_create_networks_table.php) ? creates networks
- [database/migrations/2025_01_01_000004_create_venue_types_table.php](../../database/migrations/2025_01_01_000004_create_venue_types_table.php) ? creates venue_types
- [database/migrations/2025_01_01_000005_create_sites_table.php](../../database/migrations/2025_01_01_000005_create_sites_table.php) ? creates sites
- [database/migrations/2025_01_01_000006_create_screens_table.php](../../database/migrations/2025_01_01_000006_create_screens_table.php) ? creates screens
- [database/migrations/2025_01_01_000007_create_screen_specs_table.php](../../database/migrations/2025_01_01_000007_create_screen_specs_table.php) ? creates screen_specs
- [database/migrations/2025_01_01_000008_create_screen_inventory_table.php](../../database/migrations/2025_01_01_000008_create_screen_inventory_table.php) ? creates screen_inventory
- [database/migrations/2025_01_01_000009_create_screen_impression_multipliers_table.php](../../database/migrations/2025_01_01_000009_create_screen_impression_multipliers_table.php) ? creates screen_impression_multipliers
- [database/migrations/2025_01_01_000010_create_screen_external_ids_table.php](../../database/migrations/2025_01_01_000010_create_screen_external_ids_table.php) ? creates screen_external_ids
- [database/migrations/2025_01_01_000011_create_impression_logs_table.php](../../database/migrations/2025_01_01_000011_create_impression_logs_table.php) ? creates impression_logs
- [database/migrations/2025_01_01_000012_add_owner_fields_to_users_table.php](../../database/migrations/2025_01_01_000012_add_owner_fields_to_users_table.php)
- [database/migrations/2025_01_01_000013_update_owner_users_role_enum.php](../../database/migrations/2025_01_01_000013_update_owner_users_role_enum.php)
- [database/migrations/2025_01_01_000014_update_venue_types_add_openooh_fields.php](../../database/migrations/2025_01_01_000014_update_venue_types_add_openooh_fields.php)
- [database/migrations/2026_03_14_111143_create_permission_tables.php](../../database/migrations/2026_03_14_111143_create_permission_tables.php)
- [database/migrations/2026_03_14_112135_create_personal_access_tokens_table.php](../../database/migrations/2026_03_14_112135_create_personal_access_tokens_table.php) ? creates personal_access_tokens
- [database/migrations/2026_03_19_000001_add_missing_fields_to_screen_inventory.php](../../database/migrations/2026_03_19_000001_add_missing_fields_to_screen_inventory.php)
- [database/migrations/2026_03_19_000002_add_unit_fields_to_screen_specs.php](../../database/migrations/2026_03_19_000002_add_unit_fields_to_screen_specs.php)
- [database/migrations/2026_03_19_000003_add_listing_flags_to_screen_inventory.php](../../database/migrations/2026_03_19_000003_add_listing_flags_to_screen_inventory.php)
- [database/migrations/2026_03_19_000004_add_resolution_preset_to_screen_specs.php](../../database/migrations/2026_03_19_000004_add_resolution_preset_to_screen_specs.php)
- [database/migrations/2026_03_20_000001_add_performance_indexes.php](../../database/migrations/2026_03_20_000001_add_performance_indexes.php)
- [database/migrations/2026_03_20_000002_create_vietnam_provinces_table.php](../../database/migrations/2026_03_20_000002_create_vietnam_provinces_table.php) ? creates vietnam_provinces
- [database/migrations/2026_03_20_000003_create_vietnam_communes_table.php](../../database/migrations/2026_03_20_000003_create_vietnam_communes_table.php) ? creates vietnam_communes
- [database/migrations/2026_03_20_000004_add_province_commune_to_sites.php](../../database/migrations/2026_03_20_000004_add_province_commune_to_sites.php)
- [database/migrations/2026_03_20_000005_create_vietnam_regions_and_update_provinces.php](../../database/migrations/2026_03_20_000005_create_vietnam_regions_and_update_provinces.php) ? creates vietnam_regions
- [database/migrations/2026_03_21_101225_create_api_clients_table.php](../../database/migrations/2026_03_21_101225_create_api_clients_table.php) ? creates api_clients
- [database/migrations/2026_03_21_115328_create_webhook_subscriptions_table.php](../../database/migrations/2026_03_21_115328_create_webhook_subscriptions_table.php) ? creates webhook_subscriptions
- [database/migrations/2026_03_24_000001_add_public_profile_to_owners_table.php](../../database/migrations/2026_03_24_000001_add_public_profile_to_owners_table.php)
- [database/migrations/2026_03_24_000004_add_code_to_networks_table.php](../../database/migrations/2026_03_24_000004_add_code_to_networks_table.php)
- [database/migrations/2026_03_24_000005_add_network_and_district_to_screens.php](../../database/migrations/2026_03_24_000005_add_network_and_district_to_screens.php)
- [database/migrations/2026_04_08_000001_add_performance_indexes.php](../../database/migrations/2026_04_08_000001_add_performance_indexes.php)
- [database/migrations/2026_04_10_000001_create_venue_categories_table.php](../../database/migrations/2026_04_10_000001_create_venue_categories_table.php) ? creates venue_categories
- [database/migrations/2026_04_10_000002_add_thumb_to_venue_categories.php](../../database/migrations/2026_04_10_000002_add_thumb_to_venue_categories.php)
- [database/migrations/2026_04_11_000001_add_logo_banner_to_networks.php](../../database/migrations/2026_04_11_000001_add_logo_banner_to_networks.php)
- [database/migrations/2026_04_11_000002_add_banner_to_sites.php](../../database/migrations/2026_04_11_000002_add_banner_to_sites.php)
- [database/migrations/2026_04_11_000003_add_vn_category_to_screen_inventory.php](../../database/migrations/2026_04_11_000003_add_vn_category_to_screen_inventory.php)
- [database/migrations/2026_04_11_133723_add_network_id_to_sites_table.php](../../database/migrations/2026_04_11_133723_add_network_id_to_sites_table.php)
- [database/migrations/2026_04_11_162601_add_photos_to_screen_specs_table.php](../../database/migrations/2026_04_11_162601_add_photos_to_screen_specs_table.php)
- [database/migrations/2026_04_11_163904_add_logo_to_sites_table.php](../../database/migrations/2026_04_11_163904_add_logo_to_sites_table.php)
- [database/migrations/2026_04_12_000001_create_organizations_table.php](../../database/migrations/2026_04_12_000001_create_organizations_table.php) ? creates organizations
- [database/migrations/2026_04_12_000002_create_organization_users_table.php](../../database/migrations/2026_04_12_000002_create_organization_users_table.php) ? creates organization_users
- [database/migrations/2026_04_12_000003_add_current_organization_id_to_users.php](../../database/migrations/2026_04_12_000003_add_current_organization_id_to_users.php)
- [database/migrations/2026_04_12_000004_create_carts_table.php](../../database/migrations/2026_04_12_000004_create_carts_table.php) ? creates carts, cart_items
- [database/migrations/2026_04_12_000005_create_campaigns_table.php](../../database/migrations/2026_04_12_000005_create_campaigns_table.php) ? creates campaigns
- [database/migrations/2026_04_12_000006_create_booking_lines_table.php](../../database/migrations/2026_04_12_000006_create_booking_lines_table.php) ? creates booking_lines
- [database/migrations/2026_04_12_000007_create_creatives_table.php](../../database/migrations/2026_04_12_000007_create_creatives_table.php) ? creates creatives, booking_line_creatives
- [database/migrations/2026_04_12_000008_create_payments_table.php](../../database/migrations/2026_04_12_000008_create_payments_table.php) ? creates payments
- [database/migrations/2026_04_12_000009_create_campaign_activities_table.php](../../database/migrations/2026_04_12_000009_create_campaign_activities_table.php) ? creates campaign_activities
- [database/migrations/2026_04_12_100001_add_photo_to_vietnam_provinces.php](../../database/migrations/2026_04_12_100001_add_photo_to_vietnam_provinces.php)
- [database/migrations/2026_04_13_000001_create_products_table.php](../../database/migrations/2026_04_13_000001_create_products_table.php) ? creates products, product_screens
- [database/migrations/2026_04_13_100001_update_products_listing_mode.php](../../database/migrations/2026_04_13_100001_update_products_listing_mode.php)
- [database/migrations/2026_04_13_100002_make_cart_items_screen_id_nullable.php](../../database/migrations/2026_04_13_100002_make_cart_items_screen_id_nullable.php)
- [database/migrations/2026_04_14_000001_add_address_to_owners.php](../../database/migrations/2026_04_14_000001_add_address_to_owners.php)
- [database/migrations/2026_04_14_000002_add_province_commune_to_owners.php](../../database/migrations/2026_04_14_000002_add_province_commune_to_owners.php)
- [database/migrations/2026_04_14_100001_add_slug_to_screens_and_sites.php](../../database/migrations/2026_04_14_100001_add_slug_to_screens_and_sites.php)
- [database/migrations/2026_04_14_100002_create_saved_items_table.php](../../database/migrations/2026_04_14_100002_create_saved_items_table.php) ? creates saved_items
- [database/migrations/2026_04_14_100003_add_verified_to_owners.php](../../database/migrations/2026_04_14_100003_add_verified_to_owners.php)
- [database/migrations/2026_04_15_100001_add_pricing_model_fields.php](../../database/migrations/2026_04_15_100001_add_pricing_model_fields.php)
- [database/migrations/2026_04_15_200001_add_vn_category_id_to_networks.php](../../database/migrations/2026_04_15_200001_add_vn_category_id_to_networks.php)
- [database/migrations/2026_04_19_000001_add_phase1_intelligence_to_screens.php](../../database/migrations/2026_04_19_000001_add_phase1_intelligence_to_screens.php)
- [database/migrations/2026_04_19_000002_create_poi_snapshots_table.php](../../database/migrations/2026_04_19_000002_create_poi_snapshots_table.php) ? creates poi_snapshots
- [database/migrations/2026_04_21_000001_create_screen_imports_table.php](../../database/migrations/2026_04_21_000001_create_screen_imports_table.php) ? creates screen_imports
- [database/migrations/2026_05_07_000001_create_user_invitations_table.php](../../database/migrations/2026_05_07_000001_create_user_invitations_table.php) ? creates user_invitations
- [database/migrations/2026_07_17_000001_create_public_reflections_table.php](../../database/migrations/2026_07_17_000001_create_public_reflections_table.php) ? creates public_reflections
- [database/migrations/2026_07_17_000002_create_policy_consents_table.php](../../database/migrations/2026_07_17_000002_create_policy_consents_table.php) ? creates policy_consents
- [database/migrations/2026_07_17_000003_add_legal_and_bank_info_to_owners.php](../../database/migrations/2026_07_17_000003_add_legal_and_bank_info_to_owners.php)
- [database/migrations/2026_07_17_000004_add_owner_to_payments.php](../../database/migrations/2026_07_17_000004_add_owner_to_payments.php)
- [database/migrations/2026_07_17_000005_create_owner_reviews_table.php](../../database/migrations/2026_07_17_000005_create_owner_reviews_table.php) ? creates owner_reviews
