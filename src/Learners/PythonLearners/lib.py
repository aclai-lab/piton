import pandas as pd
import math as mt
from sklearn.tree import _tree
from sklearn.tree import export_text
from io import StringIO
import sys

class Capturing(list):  # and object of class Capturing can direct an output to a list
    def __enter__(self):
        self._stdout = sys.stdout
        sys.stdout = self._stringio = StringIO()
        return self
    def __exit__(self, *args):
        self.extend(self._stringio.getvalue().splitlines())
        del self._stringio    # free up some memory
        sys.stdout = self._stdout

def object_attrs_to_cat(df):    # for evey attribute in the dataframe, if it's of type object, it converts it to categorical
    for column_name in df:
        if df[column_name].dtypes == "object": # trova un modo per fare questo
            df[column_name] = pd.Categorical(df['class'])

def df_try(df):
    print(df.head())    # prints first 5 rows of the dataframe
    print(df.dtypes)    # prints the attribute type for every attribute in the dataframe;
                        # categorical attributes are of type object

def get_class_attr(df):
    return df.columns[-2]

def is_binary(attr):            # checks if a categorical attribute is binary
                                # what if it contains just one attribute?
                                # I consider it binary (all tuples have the same value)
    return attr.nunique() <= 2

def print_dataframe(df):    # prints all the dataframe rows
    pd.set_option('display.max_rows', df.shape[0]+1)
    print(df)


# I want to eliminate all columns that contains more than % of the data as NaN
# Then I remove every row which contains a null data for a numeric attribute
def clean_dataframe(df, cp):
    th = mt.ceil((1-cp)*len(df.index))
    print("removing cols with more than " + str(len(df.index) - th) + " NaN values\n")
    na_free = df.dropna(axis='columns', thresh=th)
    only_na = df.columns[~df.columns.isin(na_free.columns)]
    for column_name in only_na:
        print(column_name + " has been removed")
    print(str(len(only_na)) + " columns have been removed\n")
    df = na_free
    df = clear_numeric_nan(df)
    return df

def clear_numeric_nan(df):  # removes every row which contains a NaN value for a numeric attribute
    for column_name in df:
        if df[column_name].dtypes == "float64":     # for every numeric attribute
            na_free = df.dropna(subset=[column_name]) # drop row if that numeric attribute contains a NaN value
            only_na = df[~df.index.isin(na_free.index)]
            if len(only_na.index) != 0:
                print(str(len(only_na.index)) + " rows have been removed, NaN value found in numeric attribute: " + column_name)
            df = na_free
    print("\n")
    return df

def clear_categorical_nan(df):  #removes every row which contains a NaN value for a categorical attribute
    for column_name in df:
        if df[column_name].dtypes == "object":      # for every categorical attribute
            na_free = df.dropna(subset=[column_name])   # drop row if that categorical attribute contains a NaN value
            only_na = df[~df.index.isin(na_free.index)]
            if len(only_na.index) != 0:
                print(str(len(only_na.index)) + " rows have been removed, NaN value found in numeric attribute: " + column_name)
            df = na_free
    print("\n")
    return df

def replace_words(s, words):    # given a string s, replaces the key dictionary words with respective dictionary values
    for k, v in words.items():
        s = s.replace(k, v)
    return s

def contains_val(df, attr, val):    # returns True if the attribute of the df contains the val, otherwise it returns False
    class_attr = get_class_attr(df)
    class_values = df[class_attr].unique()
    val = [i for i in class_values if i == class_attr]
    if val:
        return True
    else:
        return False

def get_negative_class_value(df):   # gives the negative class attribute
    class_attr = get_class_attr(df)
    class_values = df[class_attr].unique()
    neg_class_val = [i for i in class_values if i != class_attr]
    return neg_class_val[0]

def tree_to_ruleset(decision_tree, features, class_attr_domain, domain_decode):
    '''Given a decision tree, it builds the relative normalized rule based model.

    Parameters
    ----------
    decision_tree : DecisionTreeClassifier
    The decision tree to translate into a normalized rule based model.

    features : array of tuples {name : <name>, type : <type>}
    The ordered features used for the classification (equally, the columns of the
    training dataset) and their type ("int64" or "float64").

    class_attr_domain : [negative_value, positive_value]
    The domain of the class attribute.

    domain_decode : dictionary
    The correspondence, foreach categorical attribute, between 0 and 1 and their original domain.

    Return
    ------
    Nothing, it prints the resulting rule based model to the console.
    '''
    tree_ = decision_tree.tree_ # Creates a copy of the tree
    feature_name = [            # The name of the attributes (features)
        features[i]['name'] if i != _tree.TREE_UNDEFINED else "undefined!"
        for i in tree_.feature
    ]
    feature_type = [            # The type of the attributes (features)
       features[i]['type'] if i != _tree.TREE_UNDEFINED else "undefined!"
       for i in tree_.feature
   ]
    antecedents = []            # Instantiation of the array containing the antecedents of the rule

    def print_rule(rule):
        '''Prints the given rule, given an array with the consequent as the last element,
        and the others being antecedents.

        Parameters
        ----------
        rule : array
        Array of antecedents and consequent, as [ant0, ant1 ... antN, consequent].

        Return
        ------
        Nothing, it prints the resulting rule to the console.
        '''
        consequent = rule.pop()         # Stores the consequent value and pops it from the end of the array
        last_antecedent = rule.pop()    # Stores the last antecedent and pops it from the end of the array
        rule_str = ""                   # Instantiate an empty string for the rule
        for antecedent in rule:         # Foreach antecedent in the rule
            rule_str += (antecedent + " AND ")  # Add the antecedent to the strin and add AND at the end of
                                                # the string (it is not the last antecedent, I removed it)
        rule_str += (last_antecedent + " => " + consequent)  # Concatenate the antecedents with their last
                                                             # and the consequent
        print(rule_str)                 # Prints the rule to the console

    def recurse(node, depth, antecedents, domain_decode):
        '''Recursion on the tree nodes to create the rules.

        Parameters
        ----------
        node : node of the decision tree
        Node of the decision tree.

        depth : int
        Recursion level on the decision tree.

        antecedents : array
        Antecedents so far.

        domain_decode : dictionary
        The correspondence, foreach categorical attribute, between 0 and 1 and their original domain.

        Return
        ------
        Nothing, it prints the resulting rule based model to the console.
        '''
        if(tree_.feature[node] != _tree.TREE_UNDEFINED):    # If it is not a leaf
            name = feature_name[node]
            type = feature_type[node]
            threshold = tree_.threshold[node]

            left_antecedents = antecedents.copy()
            right_antecedents = antecedents.copy()

            if (type == "float64"): # If it is a numeric attribute
                left_antecedents.append("({} <= {})".format(name, threshold))
                recurse(tree_.children_left[node], depth + 1, left_antecedents, domain_decode)      # Recursion on the left node
                right_antecedents.append("({} > {})".format(name, threshold))
                recurse(tree_.children_right[node], depth + 1, right_antecedents, domain_decode)    # Recursion on the right node
            elif (type == "int64"): # If it is a categorical attribute
                left_antecedents.append("({} = {})".format(name, domain_decode[name][0]))
                recurse(tree_.children_left[node], depth + 1, left_antecedents, domain_decode)      # Recursion on the left node
                right_antecedents.append("({} = {})".format(name, domain_decode[name][1]))
                recurse(tree_.children_right[node], depth + 1, right_antecedents, domain_decode)    # Recursion on the right node
            else:
                exit("Error: couldn't translate attribute " + name + " of type " + type + ".\n")
        else:
            rule = antecedents.copy()
            consequent = class_attr_domain[0] if tree_.value[node].T[0] != 0 else class_attr_domain[1]  # It evaluetes the classification
            rule.append(consequent)
            print_rule(rule)

    recurse(0, 1, antecedents, domain_decode)
