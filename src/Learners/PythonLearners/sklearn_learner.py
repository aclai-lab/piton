#!/usr/bin/env python

# ----- Python modules used ------------------------------------------------------------------------------------------------------
import sys
from sqlalchemy import create_engine # Needed to connect to the database
from sklearn import tree
import pandas as pd
# ----- Personal modules used ----------------------------------------------------------------------------------------------------
import lib as lib
# ----- Arguments parsing --------------------------------------------------------------------------------------------------------
classifier                = sys.argv[1]   # The classifier algorithm (CART) to be used
tableName                 = sys.argv[2]   # Name of the temporary table in the database used to communicate the dataframe
criterion                 = sys.argv[3]   # The function to measure the quality of a split (gini by default, or entropy)
splitter                  = sys.argv[4]   # The strategy used to choose the split at each node (best by default, or random)
max_depth                 = sys.argv[5]   # The maximum depth of a tree
min_samples_split         = sys.argv[6]   # The minimum number of samples required to split an internal node
min_samples_leaf          = sys.argv[7]   # The minimum number of samples required to be at a leaf node
min_weight_fraction_leaf  = sys.argv[8]   # The minimum weighted fraction of the sum total of weights (of all the input samples)
                                          # required to be at a leaf node
max_features              = sys.argv[9]   # The number of features to consider when looking for the best split
random_state              = sys.argv[10]  # Controls the randomness of the estimator
max_leaf_nodes            = sys.argv[11]  # Grow a tree with ``max_leaf_nodes`` in best-first fashion
min_impurity_decrease     = sys.argv[12]  # A node will be split if this split induces a decrease of the impurity greater than or
                                          # equal to this value
class_weight              = sys.argv[13]  # Weights associated with classes in the form ``{class_label: weight}``
ccp_alpha                 = sys.argv[14]  # Complexity parameter used for Minimal Cost-Complexity Pruning
th                        = sys.argv[15]  # Threshold that indicates the maximum % of NaN values allowed for an attribute
server                    = sys.argv[16]  # The mysql server on which it has to establish the connection
user                      = sys.argv[17]  # The mysql username used to connect to the database
pwd                       = sys.argv[18]  # The mysql user password used to connect to the database
db_name                   = sys.argv[19]  # The name of the database
# ----- Setting default values if argument is None -------------------------------------------------------------------------------
if criterion.strip() == 'None':
    criterion = "gini"
if splitter.strip() == 'None':
    splitter = "best"
if max_depth.strip() == 'None':
    max_depth = None
else:
    max_depth = int(max_depth)
if min_samples_split.strip() == 'None':
    min_samples_split = 2
else:
    min_samples_split = float(min_samples_split)
if min_samples_leaf.strip() == 'None':
    min_samples_leaf = 1
else:
    min_samples_leaf = float(min_samples_leaf)
if min_weight_fraction_leaf.strip() == 'None':
    min_weight_fraction_leaf = 0.0
else:
    min_weight_fraction_leaf = float(min_weight_fraction_leaf)
if max_features.strip() == 'None':
    max_features = None
if random_state.strip() == 'None':
    random_state = None
else:
    random_state = int(random_state)
if max_leaf_nodes.strip() == 'None':
    max_leaf_nodes = None
else:
    max_leaf_nodes = int(max_leaf_nodes)
if min_impurity_decrease.strip() == 'None':
    min_impurity_decrease = 0.0
else:
    min_impurity_decrease = float(min_impurity_decrease)
if class_weight.strip() == 'None':
    class_weight = None
else:
    class_weight = dict(class_weight)
if ccp_alpha.strip() == 'None':
    ccp_alpha = 0.0
else:
    ccp_alpha = float(ccp_alpha)
if th.strip() == 'None':
    th = 0.1    # default: maximum 10% of NaN values
else:
    th = float(th)
if server.strip() == 'None':
    print("Error connecting to the database, invalid or missing server")
    sys.exit()
if user.strip() == 'None':
    print("Error connecting to the database, invalid or missing user.")
    sys.exit()
if pwd.strip() == 'None':
    print("Error connecting to the database, invalid or missing password.")
    sys.exit()
if db_name.strip() == 'None':
    print("Error connecting to the database, invalid or missing database.")
    sys.exit()
# ----- Connection to the database -----------------------------------------------------------------------------------------------
# Obtain the MySQL connection
db_connection = 'mysql+pymysql://' + user + ':' + pwd + '@' + server + '/' + db_name
conn = create_engine(db_connection)
# --------------------------------------------------------------------------------------------------------------------------------
# ----- USING SCIKIT-LEARN'S LEARNERS TO TRAIN MODELS ----------------------------------------------------------------------------
# --------------------------------------------------------------------------------------------------------------------------------
train = pd.read_sql_table(tableName, conn)  # Reads the training data frame from the a database table
                                            # Data preprocess is done by the php package, so data is already partitioned

class_attr = lib.get_class_attr(train)              # Gets the class attribute
                                                    # For now, it appears it doesn't have to be binary in this case

train = train.drop(['__ID_piton__'], axis='columns')    # Drops the ID column (I don't need it)
train = lib.clean_dataframe(train, th)                  # Removes the attributes with more than th NaN values,
                                                        # then removes the lines with numeric NaN values

X_train = train.drop(class_attr, axis=1)
y_train = train[class_attr]

# Booleanize the categorical attributes and saves the correspondence between the attribute domain and {0,1} in domain_decode.
# This dictionary will be passed to tree_to_ruleset to create rules with categorical antecedents on the right domain
# TODO in this moment it only works with binary attributes because the package trandlates all categorical to categorical binary,
# but in the future this could be extended and non-binary categorical attributes could return, or this script could be used
# outside of the package. In that case, extend this case.
domain_decode = {}
for attr in X_train:
    if X_train[attr].dtypes == "object":
        if X_train[attr].nunique() < 2:  # Removes columns which are not informative (because all instances have the same value)
            X_train = X_train.drop(attr, axis=1)
        else:
            # Stores information about the domain to re-translate to it in the end
            domain = X_train[attr].unique() # Returns the values of the domain as {value[0], value[1]}
            domain_decode[attr] = {0 : domain[0], 1 : domain[1]} # Tells the true value of 0 and 1
            # Lambda function for booleanization: if it is value[1] then it will be 1, else (value[0]) il will be 0
            X_train[attr] = X_train[attr].map(lambda x: 1 if x==domain[1] else 0)
class_attr_domain = y_train.unique()    # Domain of the class attribute as [negative_value, positive_value]
# UPDATE: this way, the first parameter it founds will be checked as 0, the second as 1.
# But if I call this script from the piton package, the negative_value should be prefixed
# by 'NO_', so adding a control and forcing the order shouldn't be a bad idea. For semantic purpose.
if len(class_attr_domain) < 2:
    print("Error: class attribute domain must have two values")
    sys.exit()
if class_attr_domain[1].startswith("NO_"): # If they are found in inverted order (only works with the package)
    temp = class_attr_domain[0]
    class_attr_domain[0] = class_attr_domain[1]
    class_attr_domain[1] = temp

y_train = y_train.map(lambda x: 1 if x==class_attr_domain[1] else 0)

# Storing the name and the type of the features. Foreach column in X_train it will store the tuple {name : <name>, type : <type>}.
# If the attribute is numeric, the type will be "float64", if it's categorical it will be "int64".
# I do this after the booleanization (n.b.: categorical attributes would be "object" instead) because some columns are discarded in
# the process.
# This operation must be AFTER the pre-processing because i need the features to be exactly the same as the one I use for the classification!
features = []
for col in X_train.columns:
    col = {'name' : col, 'type' : str(X_train[col].dtypes)}
    features.append(col)

if classifier == "CART":  # Classification using the CART algorithm
    clf = tree.DecisionTreeClassifier(criterion=criterion, splitter=splitter, max_depth=max_depth, min_samples_split=min_samples_split,
                                        min_samples_leaf=min_samples_leaf, min_weight_fraction_leaf=min_weight_fraction_leaf,
                                        max_features=max_features, random_state=random_state, max_leaf_nodes=max_leaf_nodes,
                                        min_impurity_decrease=min_impurity_decrease, class_weight=class_weight, ccp_alpha=ccp_alpha)
    clf = clf.fit(X_train, y_train)

    # DEBUG: printinting the resulting tree
    # r = tree.export_text(clf, feature_names=fn)
    # print(r)

    # Prints to console the extracted rule based model for parsing.
    print("extracted_rule_based_model: [\n")
    lib.tree_to_ruleset(clf, features, class_attr_domain, domain_decode)
    print("\n]")
else:
    print("Error: the specified classifier is invalid. Only CART is available at the moment.")
    sys.exit()