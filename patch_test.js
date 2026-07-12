const fs = require('fs');
const filePath = 'C:/Users/steve/PhpstormProjects/NLP_Services/tests/src/Unit/Service/NlpSyncTurfProcessorTest.php';

let content = fs.readFileSync(filePath, 'utf8');
const original = content;

function applyChange(num, oldStr, newStr) {
  if (content.includes(oldStr)) {
    content = content.replace(oldStr, newStr);
    console.log('Change ' + num + ' applied OK');
  } else {
    console.log('Change ' + num + ' NOT FOUND');
  }
}

// Change 1: testProcessBatchDuplicateVoterIsDeletedFromOldTurf
applyChange(1,
  "      $this->votersMock->expects($this->once())->method('deleteVoterInTurf')->with(101);\n\n      $context = [];\n      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);\n    }",
  "      $this->votersMock->expects($this->once())->method('deleteVoterInTurf')->with(101);\n\n      $context    = [];\n      $processor  = $this->buildProcessor();\n      $processor->processBatch($this->baseArg($uri), $context);\n      $processor->processBatch($this->baseArg($uri), $context);\n    }"
);

// Change 2: testProcessBatchDuplicateVoterActivistCodeRemovedThenReapplied
applyChange(2,
  "      $removeCalled = FALSE;\n      $applyCalled  = FALSE;\n      $this->activistCodesMock->method('applyAndVerifyBatch')\n        ->willReturnCallback(function (array $vanids, string $codeName, string $action) use (&$removeCalled): array {\n          if ($action === 'Remove') { $removeCalled = TRUE; }\n          return [];\n        });\n      $this->activistCodesMock->method('setApiActivistCodeBatch')\n        ->willReturnCallback(function () use (&$applyCalled): void {\n          $applyCalled = TRUE;\n        });\n      $this->activistCodesMock->method('verifyAndRetryBatch')->willReturn([]);\n\n      $context = [];\n      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);\n\n      $this->assertTrue($removeCalled, 'applyAndVerifyBatch Remove should be called to clear old turf AC');\n      $this->assertTrue($applyCalled,  'setApiActivistCodeBatch should be called to set new turf AC');\n    }",
  "      $acSetActions = [];\n      $this->activistCodesMock->method('setApiActivistCodeBatch')\n        ->willReturnCallback(function (array $vanids, string $codeName, string $action = 'Apply') use (&$acSetActions): void {\n          $acSetActions[] = $action;\n        });\n\n      $context   = [];\n      $processor = $this->buildProcessor();\n      $processor->processBatch($this->baseArg($uri), $context);\n      $processor->processBatch($this->baseArg($uri), $context);\n\n      $this->assertContains('Remove', $acSetActions, 'setApiActivistCodeBatch Remove should be called to clear old turf AC');\n      $this->assertContains('Apply',  $acSetActions, 'setApiActivistCodeBatch Apply should be called to set new turf AC');\n      $removePos = array_search('Remove', $acSetActions);\n      $applyPos  = array_search('Apply',  $acSetActions);\n      $this->assertLessThan($applyPos, $removePos, 'Remove must precede Apply');\n    }"
);

// Change 3: testProcessBatchSingleBatchSuccessHasCorrectResults
applyChange(3,
  "      $context = [];\n      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);\n\n      $this->assertSame('success', $context['results']['result']);\n      $this->assertSame(1,         $context['results']['turfCnt']);\n      $this->assertSame('Elm Street', $context['results']['turfName']);\n      $this->assertSame('Alice',   $context['results']['nickname']);\n      $this->assertSame(7,         $context['results']['turfIndex']);\n    }",
  "      $context   = [];\n      $processor = $this->buildProcessor();\n      $processor->processBatch($this->baseArg($uri), $context);\n      $processor->processBatch($this->baseArg($uri), $context);\n\n      $this->assertSame('success',    $context['results']['result']);\n      $this->assertSame(1,            $context['results']['turfCnt']);\n      $this->assertSame('Elm Street', $context['results']['turfName']);\n      $this->assertSame('Alice',      $context['results']['nickname']);\n      $this->assertSame(7,            $context['results']['turfIndex']);\n    }"
);

// Change 4: testProcessBatchMovedVoterCarriedIntoSuccessResults
applyChange(4,
  "      $context = [];\n      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);\n\n      $this->assertSame('success', $context['results']['result']);\n      $this->assertArrayHasKey(101, $context['results']['movedVoters']);",
  "      $context   = [];\n      $processor = $this->buildProcessor();\n      $processor->processBatch($this->baseArg($uri), $context);\n      $processor->processBatch($this->baseArg($uri), $context);\n\n      $this->assertSame('success', $context['results']['result']);\n      $this->assertArrayHasKey(101, $context['results']['movedVoters']);"
);

// Change 5: testProcessBatchCreatesTurfVoterRecordForEachVoter
applyChange(5,
  "      $this->votersMock->expects($this->exactly(2))->method('createTurfVoter');\n\n      $context = [];\n      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);\n\n      $this->assertSame(2, $context['results']['turfCnt']);\n    }",
  "      $this->votersMock->expects($this->exactly(2))->method('createTurfVoter');\n\n      $context   = [];\n      $processor = $this->buildProcessor();\n      $processor->processBatch($this->baseArg($uri), $context);\n      $processor->processBatch($this->baseArg($uri), $context);\n\n      $this->assertSame(2, $context['results']['turfCnt']);\n    }"
);

// Change 6: testProcessBatchCallsNlParticipationOnSuccess
applyChange(6,
  "      $this->awardsMock->expects($this->once())->method('nlParticipation')\n        ->with(42, 'Turf synced');\n\n      $context = [];\n      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);\n    }",
  "      $this->awardsMock->expects($this->once())->method('nlParticipation')\n        ->with(42, 'Turf synced');\n\n      $context   = [];\n      $processor = $this->buildProcessor();\n      $processor->processBatch($this->baseArg($uri), $context);\n      $processor->processBatch($this->baseArg($uri), $context);\n    }"
);

// Change 7: testProcessBatchMultiBatchSecondCallFinalisesSuccessfully
applyChange(7,
  "      $context = [];\n      $processor = $this->buildProcessor();\n\n      $processor->processBatch($this->baseArg($uri), $context);\n      $this->assertSame(0.5, $context['finished']);\n\n      $processor->processBatch($this->baseArg($uri), $context);\n      $this->assertSame('success', $context['results']['result']);\n    }",
  "      $context   = [];\n      $processor = $this->buildProcessor();\n\n      $processor->processBatch($this->baseArg($uri), $context);\n      $this->assertSame(0.5, $context['finished']);\n\n      $processor->processBatch($this->baseArg($uri), $context);\n      $this->assertSame(0.99, $context['finished'], 'Second call should enter awaitingApply phase');\n\n      $processor->processBatch($this->baseArg($uri), $context);\n      $this->assertSame('success', $context['results']['result']);\n    }"
);

if (content !== original) {
  fs.writeFileSync(filePath, content, 'utf8');
  console.log('File written successfully');
} else {
  console.log('No changes made (content identical to original)');
}
