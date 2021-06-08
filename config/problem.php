<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Training Mode
    |--------------------------------------------------------------------------
    |
    | Available values:
    | - "FullTraining" (trains and test onto the same 100% of data)
    | - [train_w, test_w] (train/test split according to these two weights)
    |
    */

    'trainingMode' => [
        .8,
        .2
    ],

    /*
    |--------------------------------------------------------------------------
    | Cut Off Value
    |--------------------------------------------------------------------------
    |
    | The cut off value is the value between 0 and 1 representing the minimum percentage
    | of any of the two classes (in the binary classification case) that is needed
    | for telling whether a dataset is too unbalanced to be good, or not.
    |
    */

    'cutOffValue' => 0.10,

    /*
    |--------------------------------------------------------------------------
    | Default Options
    |--------------------------------------------------------------------------
    |
    | Default options, to be set via ->setDefaultOption().
    |
    */

    'defaultOptions' => [
        /* Default language for text pre-processing */
        [
            "textLanguage",
            "it"
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Input Tables
    |--------------------------------------------------------------------------
    |
    | The database tables where the input columns are (array of table-terms, one for each table)
    |
    | For each table, the name must be specified. The name alone is sufficient for
    | the first specified table, so the first term can be the name in the form of a string (e.g. "patient").
    | For the remaining tables, join criteria can be specified, by means of 'joinClauses' and 'joinType'.
    | If one wants to specify these parameters, then the table-term should be an array
    |   [tableName, joinClauses=[], joinType="INNER JOIN"].
    | joinClauses is a list of 'MySQL constraint strings' such as "patent.ID = report.patientID",
    | used in the JOIN operation. If a single constraint is desired, then joinClauses can also simply be the string
    | representing the constraint (as compared to the array containing the single constraint).
    | The join type, defaulted to "INNER JOIN", is the MySQL type of join.
    | By default, an example of configuration is given.
    |
    */

    'inputTables' => [
    ],

    /*
    |--------------------------------------------------------------------------
    | Where Clauses
    |--------------------------------------------------------------------------
    |
    | SQL WHERE clauses for the concerning inputTables (array of {array of strings, or single string})
    |
    | The input array provides, for each recursion level, the set of where clauses (to be joined with AND's).
    | For example:
    | - [["patient.Age > 30"]]
    | - ["patient.Age > 30"]
    |   -> at the first level: "...WHERE patient.Age > 30..."
    |   -> at the second level: (no WHERE clause)
    | - [["patient.Age > 30", "patient.Name IS NOT NULL"], []]
    |   -> at the first level: "...WHERE patient.Age > 30 AND patient.Name IS NOT NULL..."
    |   -> at the second level: (no WHERE clause)
    | By default, an example of configuration is given.
    |
    */

    'whereClauses' => [
    ],

    /*
    |--------------------------------------------------------------------------
    | Order By Clauses
    |--------------------------------------------------------------------------
    |
    | SQL ORDER BY clauses (array of strings, or single string)
    |
    | Differently from whereClauses, the ORDER BY clauses are fixed at all levels.
    | For example:
    | - [["patient.Age", "DESC"]]
    |   -> "...ORDER BY patient.ID DESC..."
    | - ["patient.Age", ["patient.ID", "DESC"]]
    |   -> "...ORDER BY patient.Age, patient.ID DESC..."
    | By default, an example of configuration is given.
    |
    */

    'orderByClauses' => [
    ],

    /*
    |--------------------------------------------------------------------------
    | Identifier Column Name
    |--------------------------------------------------------------------------
    |
    | An identifier column, used for
    | - sql-based prediction
    | - a correct retrieval step of prediction results
    | Furthermore, a value for the identifier column identifies a set of data rows that are to be
    |   compressed into a single data instance before use.
    | By default, an example of configuration is given.
    |
    */

    'identifierColumnName' => null,

    /*
    |--------------------------------------------------------------------------
    | Input Columns
    |--------------------------------------------------------------------------
    |
    | Input columns. (array of inputColumn-terms, one for each column)
    |
    | For each input column, the name must be specified, and it makes up sufficient information.
    | As such, a term can simply be the name of the input column (e.g. "Age").
    | When dealing with more than one MySQL table, it is mandatory that each column name references the table
    | it belongs using the dot notation, as in "patient.Age".
    | Additional parameters can be supplied for managing the column pre-processing.
    | The generic form for a column-term is [columnName, treatment=NULL, attrName=columnName].
    | - A "treatment" for a column determines how to derive an attribute from the
    |    column data. For example, "YearsSince" translates each value of
    |    a date/datetime column into an attribute value representing the number of
    |    years since the date. "DaysSince", "MonthsSince" are also available.
    |   "DaysSince" is the default treatment for dates/datetimes
    |   "ForceCategorical" forces the corresponding attribute to be nominal. If the column is an enum fields,
    |   the enum domain will be inherited, otherwise a domain will be built using the unique values found in the column.
    |   "ForceCategoricalBinary" takes one step further and translates the nominal attribute to become a set of k binary
    |   attributes, with k the original number of classes.
    |
    |   For text fields, "BinaryBagOfWords" can be used to generate k binary attributes, each representing the presence
    |   of one of the most frequent words.
    |   When a treatment is desired, the column-term must be an array
    |    [columnName, treatment=NULL] (e.g. ["BirthDate", "ForceCategorical"])
    |   Treatments may require/allow arguments, and these can be supplied through
    |    an array instead of a simple string. For example, "BinaryBagOfWords"
    |    requires a parameter k, representing the size of the dictionary.
    |    As an example, the following term requires BinaryBagOfWords with k=10:
    |    ["Description", ["BinaryBagOfWords", 10]].
    |   The treatment for input column is defaulted to NULL, which implies no such pre-processing step.
    |   Note that the module complains whenever it encounter text fields with no treatment specified.
    |   When dealing with many text fields, consider setting the default option "textTreatment"
    |   via ->setDefaultOption(). For example, ->setDefaultOption("textTreatment", ["BinaryBagOfWords", 10]).
    | - The name of the attribute derived from the column can also be specified:
    |    for instance, ["BirthDate", "YearsSince", "Age"] creates an "Age" attribute
    |    by processing a "BirthDate" sql column.
    | By default, an example of configuration is given.
    |
    */
    'inputColumns' => [
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Columns
    |--------------------------------------------------------------------------
    |
    | Columns that are to be treated as output.
    |   (array of outputColumn-terms, one for each column)
    |
    | This module supports hierarchical models. This means that a unique DBFit object can be used to train different
    | models at predicting different output columns that are inter-related, with different sets of data.
    | In the simplest case, the user specifies a unique output column, from which M attributes are generated.
    | Then, M models are generated, each predicting an attribute value, which is then used for deriving a value for
    | the output column.
    | One can then take this a step further and, for each of the M models, independently train K models, where K is
    | the number of output classes of the attribute, using data that is only relevant to that given output class and
    | model. Generally, this hierarchical training and prediction structur takes the form of a tree with depth O
    | (number of "nested" outputColumns).
    | Having said this, the outputColumns array specifies one column per each depth of the recursion tree.
    |
    | outputColumn-terms are very similar to inputColumn-terms (see documentation for inputColumns a few lines above),
    | with a few major differences:
    | - The default treatment is "ForceCategorical": note, in fact, that output columns must generate categorical
    | attributes (this module only supports classification and not regression). Also consider using
    | "ForceCategoricalBinary", which breaks a nominal class attribute into k disjoint binary attributes.
    | - Each output column can be derived from join operations (thus it can also belong to inputTables that are not
    | in $this->inputTables).
    | Additional join criteria can be specified using table-terms format (see documentation for inputTables).
    | The format for an outputColumn is thus [columnName, tables=[], treatment="ForceCategorical"].
    | TODO [ ... , attrName=columnName], where tables is an array of table-terms.
    |
    | As such, the following is a valid outputColumns array:
    | [
    |   // first outputColumn
    |   ["report.Status",
    |     [
    |       ["RaccomandazioniTerapeuticheUnitarie",
    |           ["RaccomandazioniTerapeuticheUnitarie.ID_RACCOMANDAZIONE_TERAPEUTICA = RaccomandazioniTerapeutiche.ID"]]
    |     ],
    |     "ForceCategoricalBinary"
    |   ],
    |   // second outputColumn
    |   ["PrincipiAttivi.NOME",
    |     [
    |       ["ElementiTerapici", ["report.ID = Recommandations.reportID"]],
    |       ["PrincipiAttivi", "ElementiTerapici.PrAttID = PrincipiAttivi.ID"]
    |     ]
    |   ]
    | ]
    | By default, an example of configuration is given.
    |
    */

    'outputColumns' => [
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Node Order
    |--------------------------------------------------------------------------
    | This array is used to tweak the (generally random) order in which the problems are discovered and solved.
    | By default, an example of configuration is given.
    |
    */

    'globalNodeOrder' => [
    ],
];