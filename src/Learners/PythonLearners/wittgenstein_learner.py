#!/usr/bin/env python

# ----- Python modules used --------------------------------------------------------------------------------------------
import sys
import ast
import re
import numpy as np
import pandas as pd
import wittgenstein as lw
from sqlalchemy import create_engine # Needed to connect to the database
# ----- Personal modules used ------------------------------------------------------------------------------------------
import lib as lib
# ----- Arguments parsing ----------------------------------------------------------------------------------------------
classifier          = sys.argv[1]   # The classifier algorithm (IREP or RIPPERk) to be used
tableName           = sys.argv[2]   # Name of the temporary table in the database used to communicate the dataframe
k                   = sys.argv[3]   # Number of RIPPERk optimization iterations
dl_allowance        = sys.argv[4]   # Description length allowance
prune_size          = sys.argv[5]   # Proportion of training set to be used for pruning
n_discretize_bins   = sys.argv[6]   # Maximum of discrete bins for apparent numeric attributes fitting
max_rules           = sys.argv[7]   # Maximum number of rules
max_rule_conds      = sys.argv[8]   # Maximum number of conds per rule
max_total_conds     = sys.argv[9]   # Maximum number of total conds in entire ruleset
random_state        = sys.argv[10]  # Random seed for repeatable results
verbosity           = sys.argv[11]  # Verbosity of the output progress, model development, and/or computation
th                  = sys.argv[12]  # Threshold that indicates the maximum % of NaN values allowed for an attribute
server              = sys.argv[13]  # The mysql server on which it has to establish the connection
user                = sys.argv[14]  # The mysql username used to connect to the database
pwd                 = sys.argv[15]  # The mysql user password used to connect to the database
db_name             = sys.argv[16]  # The name of the database
# ----- Setting default values if argument is None ---------------------------------------------------------------------
if k.strip() == 'None':
    k = 2
else:
    k = int(k)
if dl_allowance.strip() == 'None':
    dl_allowance = 64
else:
    dl_allowance = int(dl_allowance)
if prune_size.strip() == 'None':
    prune_size = .33
else:
    prune_size = float(prune_size)
if n_discretize_bins.strip() == 'None':
    n_discretize_bins = 10
else:
    n_discretize_bins = int(n_discretize_bins)
if max_rules.strip() == 'None':
    max_rules = None
else:
    max_rules = int(max_rules)
if max_rule_conds.strip() == 'None':
    max_rule_conds = None
else:
    max_rule_conds = int(max_rule_conds)
if max_total_conds.strip() == 'None':
    max_total_conds = None
else:
    max_total_conds = int(max_total_conds)
if random_state.strip() == 'None':
    #random_state = None
    random_state = 1    # DEBUG
else:
    random_state = int(random_state)
if verbosity.strip() == 'None':
    verbosity = 0
else:
    verbosity = int(verbosity)
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
# ----------------------------------------------------------------------------------------------------------------------
# ----- USING WITTGENSTEIN'S LEARNERS TO TRAIN MODELS ------------------------------------------------------------------
# ----------------------------------------------------------------------------------------------------------------------
train = pd.read_sql_table(tableName, conn)  # Reads the training data frame from the a database table
                                            # Data preprocess is done by the php package, so data is already partitioned

class_attr = lib.get_class_attr(train)              # Gets the class attribute
if not lib.is_binary(train[class_attr]):            # First, we must check if the class attribute is binary
    print("Error: class attribute is not binary")
    sys.exit()

# Gets the domain of the class attribute as [negative_value, positive_value]
class_attr_domain = {}
class_attr_domain = train[class_attr].unique()
# UPDATE: this way, the first parameter it founds will be checked as 0, the second as 1.
# But if I call this script from the piton package, the negative_value should be prefixed
# by 'NO_', so adding a control and forcing the order shouldn't be a bad idea. For semantic purpose.
if class_attr_domain[1].startswith("NO_"): # If they are found in inverted order (only works with the package)
    temp = class_attr_domain[0]
    class_attr_domain[0] = class_attr_domain[1]
    class_attr_domain[1] = temp

train = train.drop(['__ID_piton__'], axis='columns')    # Drops the ID column (I don't need it)
train = lib.clean_dataframe(train, th)                  # Removes the attributes with more than th NaN values,
                                                        # then removes the lines with numeric NaN values

# Before fitting, I replace all attributes names with x1, x2 ... xN and save the reference into a dictionary;
# I do this because ripper rules remove spaces, and I will then reconstruct them when the model is ready
i = 0
attribute_encode = {}                                   # The dictionary for encoding
for attribute in train.columns:
    attribute_encode[attribute] = "X" + str(i) + "X"    # I need the final X;
                                                        # otherwise, for example, X10 and X16 will both be decoded as X1
    i+=1

attribute_decode = {v: k for k, v in attribute_encode.items()}  # The dictionary for decoding; it also contains rules to
                                                                # parse the operators in the format I will use in php to
                                                                # generate the model
attribute_decode[" ^ "] = ") AND ("
attribute_decode["="] = " = "
attribute_decode["V"] = "=> " + class_attr_domain[1] + "\n"
attribute_decode["[["] = "("
attribute_decode["]]"] = ")"
attribute_decode["["] = "("
attribute_decode["]"] = ")"

# I have the same problems with the domains of categorical attributes
# I continue using the same index, to be sure not to duplicate anything
domain_decode = {}
for attribute in train.columns:
    if train[attribute].dtypes == "object":
        if train[attribute].nunique() < 2:  # Removes columns which are not informative (because all instances have the same value)
            train = train.drop(attribute, axis=1)
        elif attribute != class_attr:
            # Stores information about the domain to re-translate to it in the end
            domain = train[attribute].unique() # Returns the values of the domain as {value[0], value[1]}
            domain_decode[str("X" + str(i) + "X")] = domain[0]
            domain_decode[str("Y" + str(i) + "Y")] = domain[1]
            train[attribute] = train[attribute].map(lambda x: str("Y" + str(i) + "Y") if x==domain[1] else str("X" + str(i) + "X"))
            i+=1

train = train.rename(columns=attribute_encode)  # Attributes encoding

if classifier == "RIPPERk":  # Classification using the RIPPERk algorithm
    ripper_clf = lw.RIPPER(k=k, dl_allowance=dl_allowance, prune_size=prune_size, n_discretize_bins=n_discretize_bins,
                           max_rules=max_rules, max_rule_conds=max_rule_conds, max_total_conds=max_total_conds,
                           random_state=random_state, verbosity=verbosity)
    # Use fit method to train a RIPPER or IREP classifier
    ripper_clf.fit(train, class_feat=attribute_encode[class_attr], pos_class=class_attr_domain[0])
    # Access the underlying model with the ruleset_ attribute, or output it with out_model().
    # A ruleset is a disjunction of conjunctions-- 'V' represents 'or'; '^' represents 'and'.
    # In other words, the model predicts positive class if any of the inner-nested condition-combinations are all true.

    # Output redirection for parsing
    with lib.Capturing() as output:
        ripper_clf.out_model()
elif classifier == "IREP":  # Classification using the IREP algorithm
    irep_clf = lw.IREP(prune_size=prune_size, n_discretize_bins=n_discretize_bins, max_rules=max_rules,
                       max_rule_conds=max_rule_conds, random_state=random_state, verbosity=verbosity)
    # Use fit method to train a RIPPER or IREP classifier
    irep_clf.fit(train, class_feat=attribute_encode[class_attr], pos_class=class_attr_domain[1])
    # Access the underlying model with the ruleset_ attribute, or output it with out_model().
    # A ruleset is a disjunction of conjunctions-- 'V' represents 'or'; '^' represents 'and'.
    # In other words, the model predicts positive class if any of the inner-nested condition-combinations are all true.

    # Output redirection for parsing
    with lib.Capturing() as output:
        irep_clf.out_model()
else:
    print("Error: the specified classifier is invalid. Please choose between RIPPERk and IREP.")
    sys.exit()

# A first parsing/decoding step using the dictionary; it does not include range parsing
rules = []
for rule in output:
    rule = lib.replace_words(rule, attribute_decode)
    rule = lib.replace_words(rule, domain_decode)
    rules.append(rule)

# Second step of parsing/decoding, using regular expressions to parse from range form to >= and <= antecedents
p = re.compile(r'\([^=]*=\s\-?\d+\.?\d*\-+\d+\.?\d*\)')
for idx, rule in enumerate(rules):
    matches = p.findall(rule) # In matches I have the matches (attribute = [-]float-[-]float) for this rule
    for match in matches:
        pAtt = re.compile(r'\([^=]*=')   # It matches '(attribute =', then I'll remove the =, I need it atm for safety
        fAtt = pAtt.findall(match)
        att = fAtt[0]
        att = att[:-1]  # The attribute name (on this line without the '=')

        pRange = re.compile(r'=\s\-?\d+\.?\d*\-+\d+\.?\d*\)')    # It matches the range as '= left_float-right_float)',
                                                                 # floats can be negative
        fRange = pRange.findall(match)
        rng = fRange[0] # The range

        pLrng = re.compile(r'\-?\d+\.?\d*')  # It matches both values, but with this form I know the one I want is
                                             # always in the first cell
        fLrng = pLrng.findall(rng)  # Not a problem if it gives both floats, I'll always need the first
        lrng = fLrng[0] # Left numeric value of the range

        pRrng = re.compile(r'\-?\d+\.?\d*\)')    # It matches the right value of the range as '[-]right_value)'
        fRrng = pRrng.findall(rng)
        rrng = fRrng[0] # Right numeric value of the range

        s = match.replace(rng, ">= " + lrng + ") AND " + att + "<= " + rrng)   # Replaces the range with the form
                                                                               # '(att >= lv) AND (att <= rv)'
        rule = rule.replace(match, s)   # Replaces the whole range rule in the rule
    rules[idx] = rule

# Prints the extracted rule based model; I will use this form to match it in php and build a RuleBasedModel
print("extracted_rule_based_model: [\n")
for rule in rules:
    rule = lib.replace_words(rule, {"< = " : "<=", "> = " : ">="}) # Some minor adjustments;
                                                                   # these weird strings appears if a discrete
                                                                   # attribute has values such as <=value or >=value
    print(rule, end="")
print(" => " + class_attr_domain[1] + "\n() => " + class_attr_domain[0] + "\n\n]")
# ----- END --------------------------------------------------------------------------------------------------------
