<?php

namespace Drush\Drupal\Commands\core;

use Consolidation\AnnotatedCommand\CommandData;
use Drupal\Component\Gettext\PoStreamWriter;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\State\StateInterface;
use Drupal\locale\PoDatabaseReader;
use Drush\Commands\DrushCommands;
use Drush\Utils\StringUtils;

class LocaleCommands extends DrushCommands
{

    protected $moduleHandler;

    protected $state;

    /**
     * @return \Drupal\Core\Extension\ModuleHandlerInterface
     */
    public function getModuleHandler()
    {
        return $this->moduleHandler;
    }

    /**
     * @return mixed
     */
    public function getState()
    {
        return $this->state;
    }

    public function __construct(ModuleHandlerInterface $moduleHandler, StateInterface $state)
    {
        $this->moduleHandler = $moduleHandler;
        $this->state = $state;
    }

    /**
     * Checks for available translation updates.
     *
     * @command locale:check
     * @aliases locale-check
     * @validate-module-enabled locale
     */
    public function check()
    {
        $this->getModuleHandler()->loadInclude('locale', 'inc', 'locale.compare');

        // Check translation status of all translatable project in all languages.
        // First we clear the cached list of projects. Although not strictly
        // necessary, this is helpful in case the project list is out of sync.
        locale_translation_flush_projects();
        locale_translation_check_projects();

        // Execute a batch if required. A batch is only used when remote files
        // are checked.
        if (batch_get()) {
            drush_backend_batch_process();
        }
    }

    /**
     * Imports the available translation updates.
     *
     * @see TranslationStatusForm::buildForm()
     * @see TranslationStatusForm::prepareUpdateData()
     * @see TranslationStatusForm::submitForm()
     *
     * @todo This can be simplified once https://www.drupal.org/node/2631584 lands
     *   in Drupal core.
     *
     * @command locale:update
     * @aliases locale-update
     * @option langcodes A comma-separated list of language codes to update. If omitted, all translations will be updated.
     * @validate-module-enabled locale
     */
    public function update($options = ['langcodes' => self::REQ])
    {
        $module_handler = $this->getModuleHandler();
        $module_handler->loadInclude('locale', 'fetch.inc');
        $module_handler->loadInclude('locale', 'bulk.inc');

        $langcodes = [];
        foreach (locale_translation_get_status() as $project_id => $project) {
            foreach ($project as $langcode => $project_info) {
                if (!empty($project_info->type) && !in_array($langcode, $langcodes)) {
                    $langcodes[] = $langcode;
                }
            }
        }

        if ($passed_langcodes = $translationOptions['langcodes']) {
            $langcodes = array_intersect($langcodes, explode(',', $passed_langcodes));
            // @todo Not selecting any language code in the user interface results in
            //   all translations being updated, so we mimick that behavior here.
        }

        // Deduplicate the list of langcodes since each project may have added the
        // same language several times.
        $langcodes = array_unique($langcodes);

        // @todo Restricting by projects is not possible in the user interface and is
        //   broken when attempting to do it in a hook_form_alter() implementation so
        //   we do not allow for it here either.
        $projects = [];

        // Set the translation import options. This determines if existing
        // translations will be overwritten by imported strings.
        $translationOptions = _locale_translation_default_update_options();

        // If the status was updated recently we can immediately start fetching the
        // translation updates. If the status is expired we clear it an run a batch to
        // update the status and then fetch the translation updates.
        $last_checked = $this->getState()->get('locale.translation_last_checked');
        if ($last_checked < REQUEST_TIME - LOCALE_TRANSLATION_STATUS_TTL) {
            locale_translation_clear_status();
            $batch = locale_translation_batch_update_build([], $langcodes, $translationOptions);
            batch_set($batch);
        } else {
            // Set a batch to download and import translations.
            $batch = locale_translation_batch_fetch_build($projects, $langcodes, $translationOptions);
            batch_set($batch);
            // Set a batch to update configuration as well.
            if ($batch = locale_config_batch_update_components($translationOptions, $langcodes)) {
                batch_set($batch);
            }
        }

        drush_backend_batch_process();
    }

    /**
     * Exports to a gettext translation file.
     *
     * @see \Drupal\locale\Form\ExportForm::submitForm
     *
     * @throws \Exception
     *
     * @command locale:export
     * @drupal-dependencies locale
     * @option template To export the template file.
     * @option langcode The language code of the exported translations.
     * @option types String types to include, defaults to all types.
     *   Types: 'not-customized', 'customized', 'not-translated'.
     * @usage drush locale:export --langcode=nl > nl.po
     *   Export the Dutch translations with all types.
     * @usage drush locale:export --langcode=nl --types=customized,not-customized > nl.po
     *   Export the Dutch customized and not customized translations.
     * @usage drush locale:export --template > drupal.pot
     *   Export the basic template file to translate.
     * @usage drush locale:export --template --langcode=nl > nl.pot
     *   Export the Dutch template file to translate.
     * @aliases locale-export
     */
    public function export($options = ['template' => false, 'langcode' => self::OPT, 'types' => self::OPT])
    {
        $language = $this->getTranslatableLanguage($options['langcode']);
        $poreader_options = [];

        if (!(bool)$options['template']) {
            $poreader_options = $this->convertTypesToPoDbReaderOptions(StringUtils::csvToArray($options['types']));
        }

        $file_uri = drush_save_data_to_temp_file('temporary://', 'po_');
        if ($this->writePoFile($file_uri, $language, $poreader_options)) {
            $this->printFile($file_uri);
        } else {
            $this->logger()->notice(dt('Nothing to export.'));
        }
    }

    /**
     * Assure that required options are set.
     *
     * @hook validate locale:export
     */
    public function exportValidate(CommandData $commandData)
    {
        $langcode = $commandData->input()->getOption('langcode');
        $template = $commandData->input()->getOption('template');
        $types = $commandData->input()->getOption('types');

        if (!$langcode && !$template) {
            throw new \Exception(dt('Set --langcode=LANGCODE or --template, see help for more information.'));
        }
        if ($template && $types) {
            throw new \Exception(dt('No need for --types, when --template is used, see help for more information.'));
        }
    }

    /**
     * Get translatable language object.
     *
     * @param string $langcode The language code of the language object.
     * @return LanguageInterface|null
     * @throws \Exception
     */
    private function getTranslatableLanguage($langcode)
    {
        if (!$langcode) {
            return null;
        }

        $language = \Drupal::languageManager()->getLanguage($langcode);

        if (!$language) {
            throw new \Exception(dt('Language code @langcode is not configured.', [
                '@langcode' => $langcode,
            ]));
        }

        if (!$this->isTranslatable($language)) {
            throw new \Exception(dt('Language code @langcode is not translatable.', [
                '@langcode' => $langcode,
            ]));
        }

        return $language;
    }

    /**
     * Check if language is translatable.
     *
     * @param LanguageInterface $language
     * @return bool
     */
    private function isTranslatable(LanguageInterface $language)
    {
        if ($language->isLocked()) {
            return false;
        }

        if ($language->getId() != 'en') {
            return true;
        }

        return (bool)\Drupal::config('locale.settings')->get('translate_english');
    }

    /**
     * Get PODatabaseReader options for given types.
     *
     * @param array $types
     * @return array
     *   Options list with value 'true'.
     * @throws \Exception
     *   Triggered with incorrect types.
     */
    private function convertTypesToPoDbReaderOptions(array $types = [])
    {
        $valid_convertions = [
            'not_customized' => 'not-customized',
            'customized' => 'customized',
            'not_translated' => 'not-translated',
        ];

        if (empty($types)) {
            return array_fill_keys(array_keys($valid_convertions), true);
        }

        // Check for invalid conversions.
        if (array_diff($types, $valid_convertions)) {
            throw new \Exception(dt('Allowed types: @types.', [
                '@types' => implode(', ', $valid_convertions),
            ]));
        }

        // Convert Types to Options.
        $options = array_keys(array_intersect($valid_convertions, $types));

        return array_fill_keys($options, true);
    }

    /**
     * Write out the exported language or template file.
     *
     * @param string $file_uri Uri string to gather the data.
     * @param LanguageInterface|null $language The language to export.
     * @param array $options The export options for PoDatabaseReader.
     * @return bool True if successful.
     */
    private function writePoFile($file_uri, LanguageInterface $language = null, array $options = [])
    {
        $reader = new PoDatabaseReader();

        if ($language) {
            $reader->setLangcode($language->getId());
            $reader->setOptions($options);
        }

        $reader_item = $reader->readItem();
        if (empty($reader_item)) {
            return false;
        }

        $header = $reader->getHeader();
        $header->setProjectName(drush_get_context('DRUSH_DRUPAL_SITE'));
        $language_name = ($language) ? $language->getName() : '';
        $header->setLanguageName($language_name);

        $writer = new PoStreamWriter();
        $writer->setURI($file_uri);
        $writer->setHeader($header);
        $writer->open();
        $writer->writeItem($reader_item);
        $writer->writeItems($reader);
        $writer->close();

        return true;
    }
}
