<?php
namespace ShadowTranslate\Model\Behavior;

use ArrayObject;
use Cake\Database\Expression\FieldInterface;
use Cake\Event\Event;
use Cake\ORM\Behavior;
use Cake\ORM\Behavior\TranslateBehavior;
use Cake\ORM\Entity;
use Cake\ORM\Query;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * ShadowTranslate behavior
 */
class ShadowTranslateBehavior extends TranslateBehavior
{
    /**
     * Constructor
     *
     * @param \Cake\ORM\Table $table Table instance
     * @param array $config Configuration
     */
    public function __construct(Table $table, array $config = [])
    {
        $config += [
            'translationTable' => $table->alias() . 'Translations',
            'fields' => [],
            'onlyTranslated' => true,
            'strategy' => 'join'
        ];
        parent::__construct($table, $config);
    }

    /**
     * Create a hasMany association for all records
     *
     * Don't create a hasOne association here as the join conditions are modified
     * in before find - so create/modify it there
     *
     * @param array $fields - ignored
     * @param string $table - ignored
     * @param string $model - ignored
     * @param string $strategy - ignored
     *
     * @return void
     */
    public function setupFieldAssociations($fields, $table, $model, $strategy)
    {
        return;
    }

    /**
     * Callback method that listens to the `beforeFind` event in the bound
     * table. It modifies the passed query by eager loading the translated fields
     * and adding a formatter to copy the values into the main table records.
     *
     * @param \Cake\Event\Event $event The beforeFind event that was fired.
     * @param \Cake\ORM\Query $query Query
     * @param \ArrayObject $options The options for the query
     * @return void
     */
    public function beforeFind(Event $event, Query $query, $options)
    {
        $locale = $this->locale();
        $config = $this->config();
        if ($locale === $config['defaultLocale']) {
            return;
        }

        if (isset($options['filterByCurrentLocale'])) {
            $joinType = $options['filterByCurrentLocale'] ? 'INNER' : 'LEFT';
        } else {
            $joinType = $config['onlyTranslated'] ? 'INNER' : 'LEFT';
        }

        $this->_table->hasOne('translation', [
            'foreignKey' => 'id',
            'className' => $this->_translationTable->registryAlias(),
            'joinType' => $joinType,
            'strategy' => $config['strategy'],
            'dependent' => true,
            'conditions' => [
               'translation.locale' => $locale,
            ]
        ]);
        $this->_table->hasMany($this->_translationTable->alias(), [
            'foreignKey' => 'id',
            'className' => $this->_translationTable->registryAlias(),
            'joinType' => $joinType,
            'dependent' => true,
            'propertyName' => '_i18n'
        ]);

        $query
            ->contain([$this->_translationTable->alias(), 'translation'])
            ->formatResults(function ($results) use ($locale) {
                return $this->_rowMapper($results, $locale);
            }, $query::PREPEND);
    }

    /**
     * Modifies the entity before it is saved so that translated fields are persisted
     * in the database too.
     *
     * @param \Cake\Event\Event $event The beforeSave event that was fired
     * @param \Cake\ORM\Entity $entity The entity that is going to be saved
     * @param \ArrayObject $options the options passed to the save method
     * @return void
     */
    public function beforeSave(Event $event, Entity $entity, ArrayObject $options)
    {
        $locale = $entity->get('_locale') ?: $this->locale();
        $newOptions = [($this->_translationTable->alias()) => ['validate' => false]];
        $options['associated'] = $newOptions + $options['associated'];

        $this->_bundleTranslatedFields($entity);
        $bundled = $entity->get('_i18n') ?: [];

        if ($locale === $this->config('defaultLocale')) {
            return;
        }

        $values = $entity->extract($this->_config['fields'], false);
        $fields = $this->_config['fields'];
        $primaryKey = (array)$this->_table->primaryKey();
        $key = $entity->get(current($primaryKey));
        $translation = $this->_translationTable->find()
            ->select(array_merge(['id', 'locale'], $fields))
            ->where(['locale' => $locale, 'id' => $key])
            ->bufferResults(false)
            ->first();

        if (!$translation) {
            $translation = new Entity(['id' => $key, 'locale' => $locale] + $values, [
                'useSetters' => false,
                'markNew' => true
            ]);
        }
  
        $entity->set('_i18n', array_merge($bundled, [$translation]));
        $entity->set('_locale', $locale, ['setter' => false]);
        $entity->dirty('_locale', false);

        foreach ($fields as $field) {
            $entity->dirty($field, false);
        }
    }

    /**
     * Modifies the results from a table find in order to merge the translated fields
     * into each entity for a given locale.
     *
     * @param \Cake\Datasource\ResultSetInterface $results Results to map.
     * @param string $locale Locale string
     * @return \Cake\Collection\Collection
     */
    protected function _rowMapper($results, $locale)
    {
        return $results->map(function ($row) {
            if ($row === null) {
                return $row;
            }
            $fields = $this->config('fields');
            $hydrated = !is_array($row);
            if (empty($row['translation'])) {
                $row['_locale'] = $this->locale();
                unset($row['translation']);

                if ($hydrated) {
                    $row->clean();
                }

                return $row;
            }

            $translation = $row['translation'];

            $keys = $hydrated ? $translation->visibleProperties() : array_keys($translation);

            foreach ($keys as $field) {
                if ($field === 'locale') {
                    $row['_locale'] = $translation[$field];
                    continue;
                }
                if (!empty($fields) && !in_array($field, $fields)) {
                    continue;
                }
                if ($translation[$field] !== null) {
                    $row[$field] = $translation[$field];
                }
            }

            unset($row['translation']);

            if ($hydrated) {
                $row->clean();
            }

            return $row;
        });
    }

    /**
     * Modifies the results from a table find in order to merge full translation records
     * into each entity under the `_translations` key
     *
     * @param \Cake\Datasource\ResultSetInterface $results Results to modify.
     * @return \Cake\Collection\Collection
     */
    public function groupTranslations($results)
    {
        return $results->map(function ($row) {
            $fields = $this->config('fields');
            $translations = (array)$row->get('_i18n');
            $hydrated = !is_array($row);
            $result = [];
            foreach ($translations as $translation) {
                $keys = $hydrated ? $translation->visibleProperties() : array_keys($translation);
                unset($translation['id']);
                foreach ($keys as $field) {
                    if (!empty($fields) && !in_array($field, array_merge(['id', 'locale'], $fields))) {
                        unset($translation[$field]);
                    }
                }
                $result[$translation['locale']] = $translation;
            }

            $options = ['setter' => false, 'guard' => false];
            $row->set('_translations', $result, $options);
            unset($row['_i18n']);
            $row->clean();
            return $row;
        });
    }

    /**
     * Helper method used to generated multiple translated field entities
     * out of the data found in the `_translations` property in the passed
     * entity. The result will be put into its `_i18n` property
     *
     * @param \Cake\ORM\Entity $entity Entity
     * @return void
     */
    protected function _bundleTranslatedFields($entity)
    {
        $translations = (array)$entity->get('_translations');

        if (empty($translations) && !$entity->dirty('_translations')) {
            return;
        }

        $primaryKey = (array)$this->_table->primaryKey();
        $key = $entity->get(current($primaryKey));

        foreach ($translations as $lang => $translation) {
            if (!$translation->id) {
                $update = [
                    'id' => $key,
                    'locale' => $lang,
                ];
                $translation->set($update, ['setter' => false]);
            }
        }
        $entity->set('_i18n', $translations);
    }

    /**
     * Lazy define and return the translation table fields
     *
     * @return array
     */
    protected function _translationFields()
    {
        $fields = $this->config('fields');

        if ($fields) {
            return $fields;
        }

        $fields = $this->_translationTable->schema()->columns();
        $fields = array_values(array_diff($fields, ['id', 'locale']));

        $this->config('fields', $fields);

        return $fields;
    }
}
